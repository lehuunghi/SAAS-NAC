<?php

namespace App\Services;

use App\Jobs\ProvisionEnvironment;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Operation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\QuoteRequest;
use App\Models\ServiceSubscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommerceService
{
    public function issueQuote(int $requestId, int $amount, int $days, string $terms, ?int $actorId = null): Order
    {
        abort_unless($amount >= 1000 && $amount <= 1000000000000 && $days >= 1 && $days <= 366 && mb_strlen($terms) >= 10, 422, 'Giá, thời hạn và điều khoản báo giá chưa hợp lệ.');
        $request = QuoteRequest::findOrFail($requestId);

        return DB::transaction(function () use ($request, $amount, $days, $terms, $actorId) {
            Tenant::whereKey($request->tenant_id)->lockForUpdate()->firstOrFail();
            $request->refresh();
            abort_unless($request->status === 'requested', 409, 'Yêu cầu đã được xử lý.');
            $plan = config('commerce.plans.'.$request->plan_code);
            abort_unless($plan, 422);
            $order = Order::create([
                'tenant_id' => $request->tenant_id, 'quote_request_id' => $request->id,
                'number' => 'N20-'.strtoupper((string) Str::ulid()), 'plan_code' => $request->plan_code,
                'plan_name' => $plan['name'], 'amount_vnd' => $amount, 'service_days' => $days,
                'terms' => $terms, 'expires_at' => now()->addDays(7),
            ]);
            $request->update(['status' => 'quoted']);
            $this->audit($request->tenant_id, $actorId ? 'quote.issued_by_admin' : 'quote.issued_by_console', $order->id, $actorId);

            return $order;
        });
    }

    public function confirmPayment(int $orderId, string $reference, int $receivedAmount, ?int $actorId = null, bool $deferInstallation = false): Order
    {
        abort_unless(preg_match('/\A[A-Za-z0-9._:-]{4,120}\z/', $reference), 422, 'Tham chiếu giao dịch không hợp lệ.');
        $initial = Order::findOrFail($orderId);

        return DB::transaction(function () use ($initial, $reference, $receivedAmount, $actorId, $deferInstallation) {
            $tenant = Tenant::whereKey($initial->tenant_id)->lockForUpdate()->firstOrFail();
            $order = Order::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($initial->id);
            if ($order->status === 'paid') {
                abort_unless(Payment::where('tenant_id', $tenant->id)->where('order_id', $order->id)->where('reference', $reference)->where('amount_vnd', $receivedAmount)->exists(), 409, 'Đơn đã có giao dịch khác.');

                return $order;
            }
            abort_unless($tenant->status === 'active' && in_array($order->status, ['pending_payment', 'pending_review'], true), 409, 'Đơn chưa được xác nhận hoặc đã hủy.');
            abort_unless($receivedAmount === $order->amount_vnd, 422, 'Số tiền nhận phải khớp báo giá.');
            abort_if(Payment::where('reference', $reference)->exists(), 409, 'Tham chiếu đã đối soát cho đơn khác.');
            Payment::create(['tenant_id' => $tenant->id, 'order_id' => $order->id, 'amount_vnd' => $receivedAmount, 'reference' => $reference, 'verified_at' => now()]);
            $subscription = ServiceSubscription::where('tenant_id', $tenant->id)->first();
            $start = $subscription && $subscription->expires_at->isFuture() ? $subscription->expires_at->copy() : now();
            ServiceSubscription::updateOrCreate(['tenant_id' => $tenant->id], [
                'status' => 'active', 'payment_reference' => 'order:'.$order->number,
                'plan_code' => $order->plan_code, 'expires_at' => $start->addDays($order->service_days),
            ]);
            $order->update(['status' => 'paid', 'paid_at' => now()]);
            $this->audit($tenant->id, $actorId ? 'payment.verified_by_admin' : 'payment.verified_by_console', $order->id, $actorId);
            if (! $deferInstallation && in_array(config('saas.driver'), ['simulation', 'hetzner'], true) && ! Environment::where('tenant_id', $tenant->id)->exists() && (config('saas.driver') === 'simulation' || config('saas.provisioning_enabled'))) {
                $owner = $tenant->members()->wherePivot('role', 'owner')->first();
                if ($owner) {
                    $environment = Environment::create(['tenant_id' => $tenant->id, 'hostname' => $tenant->slug.'.'.config('saas.domain'), 'driver' => config('saas.driver')]);
                    $operation = Operation::create(['tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'requested_by' => $owner->id, 'idempotency_key' => (string) Str::uuid()]);
                    ProvisionEnvironment::dispatch($operation->id)->afterCommit();
                }
            }

            return $order->fresh();
        });
    }

    private function audit(int $tenantId, string $action, int $subject, ?int $actorId = null): void
    {
        AuditEvent::create(['tenant_id' => $tenantId, 'user_id' => $actorId, 'action' => $action, 'subject' => (string) $subject]);
    }
}
