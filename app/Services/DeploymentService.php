<?php

namespace App\Services;

use App\Jobs\ProvisionEnvironment;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Operation;
use App\Models\ServiceSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Provisioning\SimulationProvisioner;
use App\Services\Provisioning\SshProvisioner;
use Illuminate\Support\Facades\DB;

class DeploymentService
{
    public function request(Tenant $tenant, User $actor, string $key, string $driver, ?string $notes = null, array $ssh = []): Operation
    {
        abort_unless($actor->is_active && $actor->is_platform_admin, 403);
        abort_unless(in_array($driver, ['simulation', 'hetzner', 'ssh'], true), 422);
        abort_if($driver === 'simulation' && ! SimulationProvisioner::enabled(), 409, 'Không cho phép mô phỏng trên hệ thống production.');
        $target = $driver === 'ssh' ? SshProvisioner::validateTarget($ssh) : [];

        return DB::transaction(function () use ($tenant, $actor, $key, $driver, $notes, $target) {
            $current = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === 'active', 409, 'Khách hàng đang tạm ngưng.');
            $existing = Operation::where('tenant_id', $tenant->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            abort_if(Environment::where('tenant_id', $tenant->id)->exists(), 409, 'Khách hàng đã có môi trường. Không cài đè dữ liệu.');
            if ($driver !== 'simulation') {
                abort_unless(config('saas.provisioning_enabled'), 409, 'Chưa bật cấp phát thực tế.');
                abort_unless(ServiceSubscription::where('tenant_id', $tenant->id)->where('status', 'active')->where('expires_at', '>', now())->exists(), 409, 'Khách hàng chưa có thuê bao hiệu lực.');
            }
            if ($driver === 'ssh') {
                abort_unless(config('saas.ssh_enabled'), 409, 'Chưa bật cài đặt qua SSH.');
                abort_if(Environment::where('ssh_host', $target['ssh_host'])->orWhere('ip_address', $target['ssh_host'])->exists(), 409, 'Máy chủ đã được gán cho môi trường khác.');
            }
            $environment = Environment::create([
                'tenant_id' => $tenant->id, 'hostname' => $tenant->slug.'.'.config('saas.domain'),
                'driver' => $driver, 'release_url' => config('saas.artifact_url'), 'release_sha256' => config('saas.artifact_sha256'), 'deployment_notes' => $notes,
                ...$target,
                ...($driver === 'ssh' ? ['ip_address' => $target['ssh_host'], 'server_id' => 'ssh:'.$target['ssh_host']] : []),
            ]);
            $operation = Operation::create(['tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'requested_by' => $actor->id, 'idempotency_key' => $key]);
            AuditEvent::create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'action' => 'environment.requested_by_admin', 'subject' => (string) $environment->id]);
            ProvisionEnvironment::dispatch($operation->id)->afterCommit();

            return $operation;
        });
    }

    public function retry(Operation $operation, User $actor): void
    {
        abort_unless($actor->is_active && $actor->is_platform_admin, 403);
        DB::transaction(function () use ($operation, $actor) {
            $tenant = Tenant::whereKey($operation->tenant_id)->lockForUpdate()->firstOrFail();
            $operation->refresh();
            $environment = Environment::where('tenant_id', $operation->tenant_id)->findOrFail($operation->environment_id);
            abort_if($environment->driver === 'simulation' && ! SimulationProvisioner::enabled(), 409, 'Không thử lại mô phỏng trên hệ thống production.');
            abort_unless(in_array($operation->status, ['failed', 'cancelled'], true) && in_array($environment->status, ['failed', 'cancelled'], true), 409, 'Chỉ thử lại tác vụ lỗi hoặc đã hủy.');
            abort_unless($tenant->status === 'active', 409, 'Khách hàng đang tạm ngưng.');
            $neverStarted = $operation->status === 'cancelled' && ! $operation->started_at;
            abort_unless($environment->driver === 'simulation' || $environment->server_id || $neverStarted, 409, 'Phải đối soát máy chủ trước khi thử lại.');
            if ($environment->driver !== 'simulation') {
                abort_unless(config('saas.provisioning_enabled'), 409, 'Chưa bật triển khai thực tế.');
                abort_unless(ServiceSubscription::where('tenant_id', $tenant->id)->where('status', 'active')->where('expires_at', '>', now())->exists(), 409, 'Thuê bao chưa có hiệu lực.');
            }
            if ($environment->driver === 'ssh') {
                abort_unless(config('saas.ssh_enabled') && $environment->ssh_password, 409, 'Cần bật cài SSH và nhập lại mật khẩu máy chủ trước khi thử lại.');
            }
            $environment->update(['status' => 'queued']);
            $operation->update(['status' => 'queued', 'stage' => 'requested', 'error_code' => null, 'requested_by' => $actor->id, 'attempt' => $operation->attempt + 1, 'started_at' => null, 'finished_at' => null]);
            ProvisionEnvironment::dispatch($operation->id)->afterCommit();
        });
    }
}
