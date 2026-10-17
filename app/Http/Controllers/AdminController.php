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
use App\Services\OperationLifecycle;
use App\Services\PasswordRecovery;
use App\Services\Provisioning\AnsibleInstaller;
use App\Services\Provisioning\SimulationProvisioner;
use App\Services\Provisioning\SshProvisioner;
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
        if (is_string($request->input('owner_email'))) {
            $request->merge(['owner_email' => strtolower(trim($request->input('owner_email')))]);
        }
        $data = $request->validate([
            'name' => 'required|string|max:150', 'slug' => 'required|regex:/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/|unique:tenants,slug',
            'create_owner' => 'sometimes|boolean',
            'owner_email' => ['required', 'email', 'max:190', $request->boolean('create_owner') ? Rule::unique('users', 'email') : Rule::exists('users', 'email')],
            'owner_name' => 'required_if:create_owner,true|nullable|string|max:100',
            'owner_password' => 'required_if:create_owner,true|nullable|string|min:12|max:128|confirmed',
            'current_password' => ['required_if:create_owner,true', 'string', 'current_password:web'],
        ]);
        $tenant = DB::transaction(function () use ($request, $data) {
            $owner = $request->boolean('create_owner')
                ? User::create(['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => $data['owner_password']])
                : User::where('email', $data['owner_email'])->where('is_active', true)->firstOrFail();
            if ($request->boolean('create_owner')) {
                $owner->forceFill(['must_change_password' => true])->save();
            }
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
        $data = $request->validate(['reference' => 'required|string|regex:/^[A-Za-z0-9._:-]{4,120}$/', 'amount_vnd' => 'required|integer|min:1000|max:1000000000000', 'confirmed_received' => 'required|accepted', 'defer_installation' => 'sometimes|boolean']);
        $result = DB::transaction(function () use ($request, $order, $data) {
            $result = app(CommerceService::class)->confirmPayment((int) $order, $data['reference'], $data['amount_vnd'], $request->user()->id, $data['defer_installation'] ?? false);
            $this->audit($request, 'payment.verified', $result->id, $result->tenant_id, ['defer_installation' => $data['defer_installation'] ?? false]);

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
        $data = $request->validate([
            'driver' => ['required', Rule::in(['simulation', 'hetzner', 'ssh'])],
            'idempotency_key' => 'required|uuid', 'notes' => 'nullable|string|max:3000', 'confirmed' => 'required|accepted',
            'current_password' => ['required_if:driver,ssh', 'string', 'current_password:web'],
            'dedicated_server' => ['required_if:driver,ssh', 'accepted_if:driver,ssh'],
            'ssh_host' => ['prohibited_unless:driver,ssh'], 'ssh_port' => ['prohibited_unless:driver,ssh'],
            'ssh_user' => ['prohibited_unless:driver,ssh'], 'ssh_password' => ['prohibited_unless:driver,ssh'], 'ssh_host_key' => ['prohibited_unless:driver,ssh'],
        ]);
        $operation = DB::transaction(function () use ($request, $tenant, $data) {
            $operation = app(DeploymentService::class)->request($tenant, $request->user(), $data['idempotency_key'], $data['driver'], $data['notes'] ?? null, $request->only(['ssh_host', 'ssh_port', 'ssh_user', 'ssh_password', 'ssh_host_key']));
            $this->audit($request, 'installation.requested', $operation->id, $tenant->id, ['driver' => $data['driver']]);

            return $operation;
        });

        return response()->json($operation->fresh(), 202);
    }

    public function checkSsh(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'current_password:web']]);
        abort_unless(config('saas.provisioning_enabled') && config('saas.ssh_enabled') && $tenant->status === 'active', 409, 'Cần bật kiểm tra SSH trên hệ thống và chọn khách hàng đang hoạt động.');
        abort_if(Environment::where('tenant_id', $tenant->id)->exists(), 409, 'Khách hàng đã có môi trường. Kiểm tra lại bằng quy trình vận hành của môi trường hiện tại.');
        $target = SshProvisioner::validateTarget($request->only(['ssh_host', 'ssh_port', 'ssh_user', 'ssh_password', 'ssh_host_key']));
        $candidate = new Environment([...$target, 'tenant_id' => $tenant->id, 'driver' => 'ssh', 'ip_address' => $target['ssh_host'], 'release_url' => config('saas.artifact_url'), 'release_sha256' => config('saas.artifact_sha256')]);
        try {
            app(AnsibleInstaller::class)->preflight($candidate);
        } catch (\Throwable) {
            return response()->json(['message' => 'Kiểm tra SSH chưa đạt. Kiểm tra IP/cổng, mật khẩu, host key, quyền root/sudo, Debian 12 và tài nguyên máy. Chưa tạo DNS hoặc cài gói.', 'code' => 'SSH_PREFLIGHT_FAILED'], 422);
        }
        $this->audit($request, 'installation.ssh_checked', $tenant->id, $tenant->id);

        return response()->json(['message' => 'SSH, quyền root/sudo, hệ điều hành, RAM/đĩa và máy dành riêng đã đạt tại thời điểm kiểm tra. Chưa tạo DNS hoặc cài gói; worker sẽ kiểm tra lại trước khi cài.']);
    }

    public function cancel(Request $request, string $operation): JsonResponse
    {
        $request->validate(['confirmed' => 'required|accepted']);
        $record = Operation::findOrFail($operation);
        app(OperationLifecycle::class)->cancel($record, $request->user());

        return response()->json($record->fresh());
    }

    public function sshCredentials(Request $request, string $environment): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['replace', 'forget'])],
            'current_password' => ['required', 'string', 'current_password:web'],
            'ssh_password' => ['required_if:action,replace', 'nullable', 'string', 'max:1024', 'regex:/\A[^\x00\r\n]+\z/u'],
        ]);
        DB::transaction(function () use ($request, $environment, $data): void {
            $tenantId = Environment::findOrFail($environment)->tenant_id;
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $record = Environment::whereKey($environment)->lockForUpdate()->firstOrFail();
            abort_unless($record->driver === 'ssh' && in_array($record->status, ['failed', 'cancelled'], true), 409, 'Chỉ thay hoặc xóa mật khẩu của tác vụ SSH đã dừng do lỗi hoặc đã hủy.');
            $record->update(['ssh_password' => $data['action'] === 'replace' ? $data['ssh_password'] : null]);
            $this->audit($request, 'installation.ssh_credentials_'.$data['action'], $record->id, $record->tenant_id);
        });

        return response()->json(['message' => 'Đã cập nhật thông tin xác thực SSH. Không tự chạy lại tác vụ.']);
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

    public function access(Environment $environment): JsonResponse
    {
        return response()->json($environment->only(['access_url', 'access_account', 'access_instructions']))->header('Cache-Control', 'no-store');
    }

    public function updateAccess(Request $request, Environment $environment): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'access_url' => ['bail', 'required', 'url:https', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) use ($environment): void {
                $url = parse_url($value);
                if (! is_array($url) || strtolower($url['host'] ?? '') !== strtolower($environment->hostname) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
                    $fail('Dùng địa chỉ HTTPS đúng tên miền môi trường NAC20, không chứa mật khẩu hoặc tham số bí mật.');
                }
            }],
            'access_account' => ['required', 'string', 'max:190'],
            'access_instructions' => ['nullable', 'string', 'max:2000'],
            'password' => ['prohibited'], 'ssh_password' => ['prohibited'], 'tenant_id' => ['prohibited'],
        ]);
        DB::transaction(function () use ($environment, $request, $data): void {
            Tenant::whereKey($environment->tenant_id)->lockForUpdate()->firstOrFail();
            $environment->refresh();
            abort_unless($environment->status === 'ready' && $environment->driver !== 'simulation' && $environment->handed_over_at, 409, 'Chỉ công bố truy cập sau khi đã nghiệm thu và bàn giao máy thật.');
            $environment->forceFill(collect($data)->only(['access_url', 'access_account', 'access_instructions'])->all())->save();
            $this->audit($request, 'environment.access_published', $environment->id, $environment->tenant_id);
        });

        return response()->json(['message' => 'Đã cập nhật thông tin truy cập cho chủ công ty và quản trị viên.']);
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

        return response()->json(['domain' => config('saas.domain'), 'live_enabled' => (bool) config('saas.provisioning_enabled'), 'ssh_enabled' => (bool) config('saas.ssh_enabled'), 'default_driver' => config('saas.driver'), 'simulation_enabled' => SimulationProvisioner::enabled(), 'password_recovery_enabled' => app(PasswordRecovery::class)->available(), 'checks' => $checks, 'release_url' => config('saas.artifact_url'), 'release_sha256' => config('saas.artifact_sha256'), 'bank_configured' => (bool) (config('commerce.bank.enabled') && config('commerce.bank.name') && config('commerce.bank.account_number') && config('commerce.bank.account_holder'))]);
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
