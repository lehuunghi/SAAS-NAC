<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Operation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\QuoteRequest;
use App\Models\ServiceSubscription;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CommerceService;
use App\Services\DeploymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function overview(): JsonResponse
    {
        return response()->json([
            'customers' => Tenant::count(), 'active_customers' => Tenant::where('status', 'active')->count(),
            'quotes_waiting' => QuoteRequest::where('status', 'requested')->count(),
            'payments_waiting' => Order::where('status', 'pending_review')->count(),
            'revenue_vnd' => (int) Payment::sum('amount_vnd'),
            'failed_operations' => Operation::where('status', 'failed')->count(),
            'open_tickets' => SupportTicket::where('status', 'open')->count(),
            'environment_count' => Environment::count(),
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => 'nullable|string|max:100']);
        $query = Tenant::query();
        if (! empty($data['search'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')->orWhere('slug', 'like', '%'.$data['search'].'%'));
        }

        return response()->json($query->latest('id')->paginate(20));
    }

    public function customer(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'tenant' => $tenant,
            'members' => $tenant->members()->get(['users.id', 'name', 'email', 'is_active']),
            'subscription' => ServiceSubscription::where('tenant_id', $tenant->id)->first(),
            'environments' => Environment::where('tenant_id', $tenant->id)->get(),
            'operations' => Operation::where('tenant_id', $tenant->id)->latest('id')->limit(30)->get(),
        ]);
    }

    public function createCustomer(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'slug' => 'required|regex:/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/|unique:tenants,slug', 'owner_email' => 'required|email|exists:users,email']);
        $tenant = DB::transaction(function () use ($request, $data) {
            $owner = User::where('email', $data['owner_email'])->where('is_active', true)->firstOrFail();
            $tenant = Tenant::create(['name' => $data['name'], 'slug' => $data['slug']]);
            $tenant->members()->attach($owner->id, ['role' => 'owner']);
            $this->audit($request, 'customer.created', $tenant->id, $tenant->id);

            return $tenant;
        });

        return response()->json($tenant, 201);
    }

    public function updateCustomer(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'status' => ['required', Rule::in(['active', 'suspended'])], 'contact_name' => 'nullable|string|max:120', 'phone' => 'nullable|string|max:40', 'tax_code' => 'nullable|string|max:40', 'address' => 'nullable|string|max:500', 'slug' => 'prohibited']);
        DB::transaction(function () use ($request, $tenant, $data) {
            $tenant->update($data);
            $this->audit($request, 'customer.updated', $tenant->id, $tenant->id, ['status' => $data['status']]);
        });

        return response()->json($tenant);
    }

    public function sales(): JsonResponse
    {
        return response()->json([
            'quotes' => QuoteRequest::latest('id')->paginate(20, ['*'], 'quotes_page'),
            'orders' => Order::latest('id')->paginate(20, ['*'], 'orders_page'),
            'payments' => Payment::latest('id')->paginate(20, ['*'], 'payments_page'),
        ]);
    }

    public function issueQuote(Request $request, string $quote): JsonResponse
    {
        $data = $request->validate(['amount_vnd' => 'required|integer|min:1000|max:1000000000000', 'service_days' => 'required|integer|min:1|max:366', 'terms' => 'required|string|min:10|max:12000']);
        $order = DB::transaction(function () use ($request, $quote, $data) {
            $order = app(CommerceService::class)->issueQuote((int) $quote, $data['amount_vnd'], $data['service_days'], $data['terms'], $request->user()->id);
            $this->audit($request, 'quote.issued', $order->id, $order->tenant_id);

            return $order;
        });

        return response()->json($order, 201);
    }

    public function verifyPayment(Request $request, string $order): JsonResponse
    {
        $data = $request->validate(['reference' => 'required|string|regex:/^[A-Za-z0-9._:-]{4,120}$/', 'amount_vnd' => 'required|integer|min:1000|max:1000000000000', 'confirmed_received' => 'required|accepted']);
        $result = DB::transaction(function () use ($request, $order, $data) {
            $result = app(CommerceService::class)->confirmPayment((int) $order, $data['reference'], $data['amount_vnd'], $request->user()->id);
            $this->audit($request, 'payment.verified', $result->id, $result->tenant_id);

            return $result;
        });

        return response()->json($result);
    }

    public function installations(): JsonResponse
    {
        return response()->json(['environments' => Environment::latest('id')->paginate(20), 'operations' => Operation::latest('id')->paginate(20, ['*'], 'operations_page')]);
    }

    public function install(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['driver' => ['required', Rule::in(['simulation', 'hetzner'])], 'idempotency_key' => 'required|uuid', 'notes' => 'nullable|string|max:3000', 'confirmed' => 'required|accepted']);
        $operation = DB::transaction(function () use ($request, $tenant, $data) {
            $operation = app(DeploymentService::class)->request($tenant, $request->user(), $data['idempotency_key'], $data['driver'], $data['notes'] ?? null);
            $this->audit($request, 'installation.requested', $operation->id, $tenant->id, ['driver' => $data['driver']]);

            return $operation;
        });

        return response()->json($operation->fresh(), 202);
    }

    public function retry(Request $request, string $operation): JsonResponse
    {
        $request->validate(['confirmed_reconciled' => 'required|accepted']);
        $record = Operation::findOrFail($operation);
        DB::transaction(function () use ($request, $record) {
            app(DeploymentService::class)->retry($record, $request->user());
            $this->audit($request, 'installation.retried', $record->id, $record->tenant_id);
        });

        return response()->json($record->fresh(), 202);
    }

    public function handover(Request $request, string $environment): JsonResponse
    {
        $data = $request->validate(['database_checked' => 'required|accepted', 'tls_checked' => 'required|accepted', 'connector_checked' => 'required|accepted', 'radius_checked' => 'required|accepted', 'backup_checked' => 'required|accepted', 'evidence' => 'required|string|min:20|max:4000']);
        $record = Environment::findOrFail($environment);
        DB::transaction(function () use ($request, $record, $data) {
            Tenant::whereKey($record->tenant_id)->lockForUpdate()->firstOrFail();
            $record->refresh();
            abort_unless($record->status === 'awaiting_configuration' && $record->driver !== 'simulation' && $record->server_id && $record->ip_address, 409, 'Chỉ bàn giao máy thật đã cài xong gói và hoàn tất cấu hình.');
            $record->update(['status' => 'ready', 'handed_over_at' => now(), 'acceptance_evidence' => $data['evidence']]);
            $this->audit($request, 'installation.handed_over', $record->id, $record->tenant_id, $data);
            AuditEvent::create(['tenant_id' => $record->tenant_id, 'user_id' => $request->user()->id, 'action' => 'environment.handed_over_by_admin', 'subject' => (string) $record->id]);
        });

        return response()->json($record);
    }

    public function users(): JsonResponse
    {
        return response()->json(User::latest('id')->paginate(20, ['id', 'name', 'email', 'is_active', 'is_platform_admin', 'created_at']));
    }

    public function updateUser(Request $request, string $user): JsonResponse
    {
        $data = $request->validate(['is_active' => 'required|boolean', 'is_platform_admin' => 'prohibited']);
        $target = User::findOrFail($user);
        abort_if($target->is_platform_admin || $target->id === $request->user()->id, 409, 'Không khóa hoặc đổi quyền tài khoản admin từ chức năng này.');
        DB::transaction(function () use ($request, $target, $data) {
            $target->forceFill($data)->save();
            $this->audit($request, 'user.status_changed', $target->id, null, $data);
        });

        return response()->json(['id' => $target->id, 'is_active' => $target->is_active]);
    }

    public function tickets(): JsonResponse
    {
        return response()->json(SupportTicket::latest('id')->paginate(20));
    }

    public function reply(Request $request, string $ticket): JsonResponse
    {
        $data = $request->validate(['reply' => 'required|string|min:5|max:6000', 'status' => ['required', Rule::in(['answered', 'closed'])]]);
        $record = SupportTicket::findOrFail($ticket);
        DB::transaction(function () use ($request, $record, $data) {
            $record->update($data);
            $this->audit($request, 'support.replied', $record->id, $record->tenant_id);
        });

        return response()->json($record);
    }

    public function settings(): JsonResponse
    {
        $checks = [];
        foreach (['hcloud.token', 'hcloud.ssh_key_id', 'hcloud.firewall_id', 'hcloud.server_type', 'hcloud.location', 'cloudflare.token', 'cloudflare.zone_id', 'ssh_key', 'known_hosts', 'artifact_url', 'artifact_sha256'] as $key) {
            $checks[$key] = (bool) config('saas.'.$key);
        }

        return response()->json(['domain' => config('saas.domain'), 'live_enabled' => (bool) config('saas.provisioning_enabled'), 'default_driver' => config('saas.driver'), 'checks' => $checks, 'release_url' => config('saas.artifact_url'), 'release_sha256' => config('saas.artifact_sha256'), 'bank_configured' => (bool) (config('commerce.bank.enabled') && config('commerce.bank.name') && config('commerce.bank.account_number') && config('commerce.bank.account_holder'))]);
    }

    public function auditLog(): JsonResponse
    {
        return response()->json(DB::table('admin_audit_events')->orderByDesc('id')->paginate(30));
    }

    private function audit(Request $request, string $action, int $subject, ?int $tenantId = null, array $details = []): void
    {
        DB::table('admin_audit_events')->insert(['user_id' => $request->user()->id, 'tenant_id' => $tenantId, 'action' => $action, 'subject' => (string) $subject, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }
}
