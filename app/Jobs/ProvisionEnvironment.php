<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Operation;
use App\Models\ServiceSubscription;
use App\Models\Tenant;
use App\Services\Provisioning\HetznerProvisioner;
use App\Services\Provisioning\SimulationProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class ProvisionEnvironment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 2400;

    public bool $failOnTimeout = true;

    public function __construct(public int $operationId)
    {
        $this->onQueue('provisioning');
    }

    public function handle(): void
    {
        $operation = Operation::findOrFail($this->operationId);
        if ($operation->status !== 'queued') {
            return;
        }
        $lock = Cache::lock('provision:tenant:'.$operation->tenant_id, 2500);
        if (! $lock->get()) {
            return;
        }
        try {
            $operation->refresh();
            if ($operation->status !== 'queued') {
                return;
            }
            $environment = Environment::where('tenant_id', $operation->tenant_id)->findOrFail($operation->environment_id);
            if (Tenant::findOrFail($operation->tenant_id)->status !== 'active') {
                throw new RuntimeException('TENANT_INACTIVE');
            }
            if (! Tenant::findOrFail($operation->tenant_id)->members()->where('users.id', $operation->requested_by)->wherePivotIn('role', ['owner', 'admin'])->exists()) {
                throw new RuntimeException('MEMBERSHIP_REVOKED');
            }
            if ($environment->driver !== 'simulation' && ! ServiceSubscription::where('tenant_id', $operation->tenant_id)->where('status', 'active')->where('expires_at', '>', now())->exists()) {
                throw new RuntimeException('SUBSCRIPTION_INACTIVE');
            }
            $operation->update(['status' => 'running', 'error_code' => null]);
            $environment->update(['status' => 'provisioning']);
            $provisioner = match ($environment->driver) {
                'simulation' => app(SimulationProvisioner::class),
                'hetzner' => config('saas.provisioning_enabled') ? app(HetznerProvisioner::class) : throw new RuntimeException('LIVE_DISABLED'),
                default => throw new RuntimeException('UNKNOWN_DRIVER'),
            };
            $provisioner->install($environment, fn (string $stage) => $operation->update(['stage' => $stage]));
            $operation->update(['status' => 'completed']);
            AuditEvent::create(['tenant_id' => $operation->tenant_id, 'user_id' => $operation->requested_by, 'action' => 'environment.'.$environment->fresh()->status, 'subject' => (string) $environment->id]);
        } catch (Throwable $exception) {
            $operation->update(['status' => 'failed', 'error_code' => 'PROVISIONING_FAILED']);
            Environment::where('tenant_id', $operation->tenant_id)->whereKey($operation->environment_id)->update(['status' => 'failed']);
            // Provider exceptions may contain credentials or request payloads: do not persist their message.
            AuditEvent::create(['tenant_id' => $operation->tenant_id, 'action' => 'environment.failed', 'subject' => (string) $operation->environment_id]);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $operation = Operation::find($this->operationId);
        if ($operation && in_array($operation->status, ['queued', 'running'], true)) {
            $operation->update(['status' => 'failed', 'error_code' => 'WORKER_INTERRUPTED']);
            Environment::where('tenant_id', $operation->tenant_id)->whereKey($operation->environment_id)->update(['status' => 'failed']);
        }
    }
}
