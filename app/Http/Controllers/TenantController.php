<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionEnvironment;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Operation;
use App\Models\ServiceSubscription;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function index(Request $request): Collection
    {
        return Tenant::query()->whereHas('members', fn ($q) => $q->where('users.id', $request->user()->id))
            ->orderBy('name')->get(['id', 'name', 'slug', 'status']);
    }

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        Gate::authorize('view', $tenant);

        return response()->json([
            'tenant' => $tenant,
            'can_manage' => Gate::allows('manage', $tenant),
            'sites' => Site::where('tenant_id', $tenant->id)->orderBy('id')->limit(100)->get(),
            'environments' => Environment::where('tenant_id', $tenant->id)->get(),
            'operations' => Operation::where('tenant_id', $tenant->id)->latest('id')->limit(20)->get(),
            'audit' => AuditEvent::where('tenant_id', $tenant->id)->latest('id')->limit(20)->get(),
        ]);
    }

    public function storeSite(Request $request, Tenant $tenant): JsonResponse
    {
        Gate::authorize('manage', $tenant);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => ['required', 'regex:/^[a-z0-9-]{2,50}$/', Rule::unique('sites')->where('tenant_id', $tenant->id)],
            'tenant_id' => 'prohibited',
        ]);
        $site = DB::transaction(function () use ($data, $tenant, $request) {
            $site = Site::create([...$data, 'tenant_id' => $tenant->id]);
            AuditEvent::create(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'action' => 'site.created', 'subject' => (string) $site->id]);

            return $site;
        });

        return response()->json($site, 201);
    }

    public function provision(Request $request, Tenant $tenant): JsonResponse
    {
        Gate::authorize('manage', $tenant);
        $data = $request->validate([
            'idempotency_key' => 'required|uuid',
            'tenant_id' => 'prohibited',
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('tenant_id', $tenant->id)],
        ]);
        $operation = DB::transaction(function () use ($data, $tenant, $request) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = Operation::where('tenant_id', $tenant->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->requested_by === $request->user()->id, 409, 'Mã yêu cầu đã được sử dụng.');

                return $existing;
            }
            abort_if(Environment::where('tenant_id', $tenant->id)->exists(), 409, 'Công ty đã có môi trường.');
            abort_unless(in_array(config('saas.driver'), ['simulation', 'hetzner'], true), 409, 'Cài SSH trên máy có sẵn phải do admin TN20 thực hiện.');
            abort_unless(config('saas.provisioning_enabled') || config('saas.driver') === 'simulation', 409, 'TN20 chưa bật cấp phát thực tế.');
            if (config('saas.driver') !== 'simulation') {
                abort_unless(ServiceSubscription::where('tenant_id', $tenant->id)->where('status', 'active')->where('expires_at', '>', now())->exists(), 409, 'Chưa có thuê bao được TN20 xác nhận.');
            }
            $environment = Environment::create([
                'tenant_id' => $tenant->id, 'site_id' => $data['site_id'] ?? null,
                'hostname' => $tenant->slug.'.'.config('saas.domain'), 'driver' => config('saas.driver'),
            ]);
            $operation = Operation::create([
                'tenant_id' => $tenant->id, 'environment_id' => $environment->id,
                'requested_by' => $request->user()->id, 'idempotency_key' => $data['idempotency_key'],
            ]);
            AuditEvent::create(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'action' => 'environment.requested', 'subject' => (string) $environment->id]);
            ProvisionEnvironment::dispatch($operation->id)->afterCommit();

            return $operation;
        });

        return response()->json($operation->fresh(), 202);
    }

    public function operation(Tenant $tenant, string $operation): Operation
    {
        Gate::authorize('view', $tenant);

        return Operation::where('tenant_id', $tenant->id)->findOrFail($operation);
    }
}
