<?php

namespace App\Jobs;

use App\Models\Environment;
use App\Models\Operation;
use App\Models\ServiceSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OperationLifecycle;
use App\Services\Provisioning\HetznerProvisioner;
use App\Services\Provisioning\SimulationProvisioner;
use App\Services\Provisioning\SshProvisioner;
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

    public int $attempt = 1;

    public function __construct(public int $operationId)
    {
        $this->attempt = Operation::find($operationId)?->attempt ?? 1;
        $this->onQueue('provisioning');
    }

    public function handle(): void
    {
        $operation = Operation::findOrFail($this->operationId);
        if ($operation->status !== 'queued' || $operation->attempt !== $this->attempt) {
            return;
        }
        $lock = Cache::lock('provision:tenant:'.$operation->tenant_id, 2500);
        if (! $lock->get()) {
            return;
        }
        try {
            $operation = app(OperationLifecycle::class)->start($this->operationId, $this->attempt);
            if (! $operation) {
                return;
            }
            $environment = Environment::where('tenant_id', $operation->tenant_id)->findOrFail($operation->environment_id);
            if (Tenant::findOrFail($operation->tenant_id)->status !== 'active') {
                throw new RuntimeException('TENANT_INACTIVE');
            }
            $actor = User::find($operation->requested_by);
            if (! $actor?->is_active || (! $actor->is_platform_admin && ! Tenant::findOrFail($operation->tenant_id)->members()->where('users.id', $operation->requested_by)->wherePivotIn('role', ['owner', 'admin'])->exists())) {
                throw new RuntimeException('MEMBERSHIP_REVOKED');
            }
            if ($environment->driver !== 'simulation' && ! ServiceSubscription::where('tenant_id', $operation->tenant_id)->where('status', 'active')->where('expires_at', '>', now())->exists()) {
                throw new RuntimeException('SUBSCRIPTION_INACTIVE');
            }
            if ($environment->driver === 'ssh' && ! $actor->is_platform_admin) {
                throw new RuntimeException('MEMBERSHIP_REVOKED');
            }
            $provisioner = match ($environment->driver) {
                'simulation' => app(SimulationProvisioner::class),
                'hetzner' => config('saas.provisioning_enabled') ? app(HetznerProvisioner::class) : throw new RuntimeException('LIVE_DISABLED'),
                'ssh' => config('saas.provisioning_enabled') && config('saas.ssh_enabled') ? app(SshProvisioner::class) : throw new RuntimeException('LIVE_DISABLED'),
                default => throw new RuntimeException('UNKNOWN_DRIVER'),
            };
            $provisioner->install($environment, function (string $stage) use ($operation): void {
                $changed = Operation::whereKey($operation->id)->where('attempt', $this->attempt)->where('status', 'running')->update(['stage' => $stage, 'updated_at' => now()]);
                if (! $changed) {
                    throw new RuntimeException('WORKER_LEASE_LOST');
                }
            });
            app(OperationLifecycle::class)->finish($operation, 'completed');
        } catch (Throwable $exception) {
            $safeCodes = ['TENANT_INACTIVE', 'MEMBERSHIP_REVOKED', 'SUBSCRIPTION_INACTIVE', 'LIVE_DISABLED', 'UNKNOWN_DRIVER', 'MISSING_CONFIGURATION', 'INVALID_CONFIGURATION', 'RECONCILE_SERVER_BEFORE_RETRY', 'INVALID_SERVER_ADDRESS', 'INVALID_SSH_TARGET', 'INVALID_HOSTNAME', 'INVALID_ZONE', 'DNS_CONFLICT', 'ANSIBLE_FAILED', 'SSH_PREFLIGHT_FAILED'];
            if ($operation) {
                app(OperationLifecycle::class)->finish($operation, 'failed', in_array($exception->getMessage(), $safeCodes, true) ? $exception->getMessage() : 'PROVISIONING_FAILED');
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $operation = Operation::find($this->operationId);
        if ($operation && $operation->attempt === $this->attempt) {
            app(OperationLifecycle::class)->finish($operation, 'failed', 'WORKER_INTERRUPTED');
        }
    }
}
