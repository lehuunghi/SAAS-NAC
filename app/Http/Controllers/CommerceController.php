<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\QuoteRequest;
use App\Models\ServiceSubscription;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CommerceController extends Controller
{
    public function catalog(): JsonResponse
    {
        return response()->json(['plans' => array_values(config('commerce.plans')), 'registration_enabled' => (bool) config('commerce.registration_enabled')]);
    }

    public function register(Request $request): JsonResponse
    {
        abort_unless(config('commerce.registration_enabled'), 409, 'Liên hệ TN20 để được cấp tài khoản.');
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => 'required|string|max:100', 'company' => 'required|string|max:150',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:12|max:200|confirmed', 'tenant_id' => 'prohibited', 'role' => 'prohibited', 'is_platform_admin' => 'prohibited', 'is_active' => 'prohibited',
        ]);
        $user = DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $tenant = Tenant::create(['name' => $data['company'], 'slug' => 'cty-'.strtolower(Str::random(18))]);
            $tenant->members()->attach($user->id, ['role' => 'owner']);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()));

        return response()->json(['name' => $user->name], 201);
    }

    public function billing(Tenant $tenant): JsonResponse
    {
        Gate::authorize('manage', $tenant);
        $bank = config('commerce.bank');
        $ready = $bank['enabled'] && $bank['name'] && $bank['account_number'] && $bank['account_holder'];

        return response()->json([
            'quotes' => QuoteRequest::where('tenant_id', $tenant->id)->latest('id')->paginate(15, ['*'], 'quotes_page'),
            'orders' => Order::where('tenant_id', $tenant->id)->latest('id')->paginate(15, ['*'], 'orders_page'),
            'payments' => Payment::where('tenant_id', $tenant->id)->latest('id')->paginate(15, ['*'], 'payments_page'),
            'subscription' => ServiceSubscription::where('tenant_id', $tenant->id)->first(),
            'bank' => $ready ? ['name' => $bank['name'], 'account_number' => $bank['account_number'], 'account_holder' => $bank['account_holder']] : null,
        ]);
    }

    public function quote(Request $request, Tenant $tenant): JsonResponse
    {
        Gate::authorize('manage', $tenant);
        $data = $request->validate([
            'plan_code' => ['required', Rule::in(array_keys(config('commerce.plans')))],
            'devices' => 'required|integer|min:1|max:1000000', 'branches' => 'required|integer|min:1|max:10000',
            'requirements' => 'nullable|string|max:4000', 'idempotency_key' => 'required|uuid',
            'tenant_id' => 'prohibited', 'amount_vnd' => 'prohibited', 'status' => 'prohibited',
        ]);
        $quote = DB::transaction(function () use ($data, $tenant, $request) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $quote = QuoteRequest::firstOrCreate(['tenant_id' => $tenant->id, 'idempotency_key' => $data['idempotency_key']], [...$data, 'requested_by' => $request->user()->id]);
            if ($quote->wasRecentlyCreated) {
                $this->audit($request, $tenant, 'quote.requested', $quote->id);
            }

            return $quote;
        });

        return response()->json($quote, 201);
    }

    public function order(Tenant $tenant, string $order): Order
    {
        Gate::authorize('manage', $tenant);

        return Order::where('tenant_id', $tenant->id)->findOrFail($order);
    }

    public function updateOrder(Request $request, Tenant $tenant, string $order): JsonResponse
    {
        Gate::authorize('manage', $tenant);
        $data = $request->validate(['action' => ['required', Rule::in(['accept', 'cancel', 'report_transfer'])], 'status' => 'prohibited', 'amount_vnd' => 'prohibited', 'tenant_id' => 'prohibited']);
        $result = DB::transaction(function () use ($request, $tenant, $order, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $record = Order::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($order);
            $action = $data['action'];
            if ($action === 'accept') {
                abort_unless($record->status === 'quoted' && $record->expires_at->isFuture(), 409, 'Báo giá đã hết hạn hoặc được xử lý.');
                $record->update(['status' => 'pending_payment', 'accepted_at' => now()]);
            } elseif ($action === 'cancel') {
                abort_unless(in_array($record->status, ['quoted', 'pending_payment'], true), 409, 'Không thể tự hủy đơn đã báo chuyển khoản hoặc thanh toán.');
                $record->update(['status' => 'cancelled']);
            } else {
                abort_unless($record->status === 'pending_payment', 409, 'Đơn không ở trạng thái chờ thanh toán.');
                $bank = config('commerce.bank');
                abort_unless($bank['enabled'] && $bank['name'] && $bank['account_number'] && $bank['account_holder'], 409, 'TN20 chưa công bố thông tin chuyển khoản.');
                $record->update(['status' => 'pending_review']);
            }
            $this->audit($request, $tenant, 'order.'.$action, $record->id);

            return $record;
        });

        return response()->json($result);
    }

    public function tickets(Tenant $tenant): JsonResponse
    {
        Gate::authorize('view', $tenant);

        return response()->json(SupportTicket::where('tenant_id', $tenant->id)->latest('id')->paginate(15));
    }

    public function storeTicket(Request $request, Tenant $tenant): JsonResponse
    {
        Gate::authorize('view', $tenant);
        $data = $request->validate(['subject' => 'required|string|max:180', 'message' => 'required|string|min:10|max:6000', 'tenant_id' => 'prohibited', 'reply' => 'prohibited', 'status' => 'prohibited']);
        $ticket = SupportTicket::create([...$data, 'tenant_id' => $tenant->id, 'user_id' => $request->user()->id]);
        $this->audit($request, $tenant, 'support.created', $ticket->id);

        return response()->json($ticket, 201);
    }

    private function audit(Request $request, Tenant $tenant, string $action, int $id): void
    {
        AuditEvent::create(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'action' => $action, 'subject' => (string) $id]);
    }
}
