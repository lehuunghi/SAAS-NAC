<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\DeploymentNotification;
use App\Models\Environment;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OperationLifecycle
{
    public function start(int $id, int $attempt): ?Operation
    {
        $tenantId = Operation::findOrFail($id)->tenant_id;

        return DB::transaction(function () use ($id, $attempt, $tenantId): ?Operation {
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $operation = Operation::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($operation->status !== 'queued' || $operation->attempt !== $attempt) {
                return null;
            }
            $operation->update(['status' => 'running', 'started_at' => now(), 'finished_at' => null, 'error_code' => null]);
            Environment::where('tenant_id', $tenantId)->whereKey($operation->environment_id)->update(['status' => 'provisioning']);

            return $operation;
        });
    }

    public function finish(Operation $expected, string $status, ?string $error = null): void
    {
        DB::transaction(function () use ($expected, $status, $error): void {
            Tenant::whereKey($expected->tenant_id)->lockForUpdate()->firstOrFail();
            $operation = Operation::whereKey($expected->id)->lockForUpdate()->firstOrFail();
            if ($operation->status !== 'running' || $operation->attempt !== $expected->attempt) {
                return;
            }
            $operation->update(['status' => $status, 'error_code' => $error, 'finished_at' => now()]);
            if ($status === 'failed') {
                Environment::where('tenant_id', $operation->tenant_id)->whereKey($operation->environment_id)->update(['status' => 'failed']);
            }
            $this->publish($operation);
        });
    }

    public function cancel(Operation $initial, User $actor): void
    {
        abort_unless($actor->is_active && $actor->is_platform_admin, 403);
        DB::transaction(function () use ($initial, $actor): void {
            Tenant::whereKey($initial->tenant_id)->lockForUpdate()->firstOrFail();
            $operation = Operation::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            if ($operation->status === 'cancelled') {
                return;
            }
            abort_unless($operation->status === 'queued', 409, 'Chỉ hủy tác vụ chưa được worker nhận. Tác vụ đang chạy cần đối soát tại vận hành.');
            $operation->update(['status' => 'cancelled', 'stage' => 'cancelled', 'error_code' => null, 'finished_at' => now()]);
            Environment::where('tenant_id', $operation->tenant_id)->whereKey($operation->environment_id)->update(['status' => 'cancelled', 'ssh_password' => null]);
            $this->publish($operation);
            AuditEvent::create(['tenant_id' => $operation->tenant_id, 'user_id' => $actor->id, 'action' => 'operation.cancelled_by_admin', 'subject' => (string) $operation->id]);
        });
    }

    public function recoverStale(): int
    {
        $cutoff = now()->subSeconds(3000);
        $count = 0;
        $candidates = Operation::where('status', 'running')->where(fn ($query) => $query->where('started_at', '<', $cutoff)->orWhere(fn ($legacy) => $legacy->whereNull('started_at')->where('updated_at', '<', $cutoff)))->limit(100)->get();
        foreach ($candidates as $operation) {
            $lock = Cache::lock('provision:tenant:'.$operation->tenant_id, 60);
            if (! $lock->get()) {
                continue;
            }
            try {
                $operation->refresh();
                if ($operation->status === 'running' && ($operation->started_at ?? $operation->updated_at)->lt($cutoff)) {
                    $this->finish($operation, 'failed', 'WORKER_STALE');
                    $count++;
                }
            } finally {
                $lock->release();
            }
        }

        return $count;
    }

    private function publish(Operation $operation): void
    {
        $ids = Tenant::findOrFail($operation->tenant_id)->members()->where('is_active', true)->pluck('users.id')
            ->merge(User::where('is_active', true)->where('is_platform_admin', true)->pluck('id'))->unique();
        $title = match ($operation->status) {
            'completed' => 'Tác vụ cài đặt đã hoàn tất',
            'cancelled' => 'Tác vụ đang chờ đã được hủy',
            default => 'Tác vụ cài đặt cần xử lý',
        };
        $message = match ($operation->status) {
            'completed' => 'Xem trạng thái môi trường. Máy thật vẫn cần cấu hình và nghiệm thu trước bàn giao; mô phỏng không tạo máy chủ.',
            'cancelled' => 'Đã xóa mật khẩu SSH đã lưu. Không xóa máy chủ, DNS hoặc hoàn tác phần cài của lần chạy trước.',
            default => 'Mã xử lý: '.($operation->error_code ?? 'PROVISIONING_FAILED').'. TN20 cần đối soát trước khi thử lại.',
        };
        foreach ($ids as $id) {
            DeploymentNotification::firstOrCreate(['operation_id' => $operation->id, 'user_id' => $id, 'attempt' => $operation->attempt], [
                'tenant_id' => $operation->tenant_id, 'status' => $operation->status, 'title' => $title, 'message' => $message,
            ]);
        }
        AuditEvent::create(['tenant_id' => $operation->tenant_id, 'action' => 'operation.'.$operation->status, 'subject' => (string) $operation->id]);
    }
}
