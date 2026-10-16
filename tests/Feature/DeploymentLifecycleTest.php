<?php

namespace Tests\Feature;

use App\Jobs\ProvisionEnvironment;
use App\Models\DeploymentNotification;
use App\Models\Environment;
use App\Models\Operation;
use App\Models\ServiceSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OperationLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeploymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Lifecycle-Admin-Password!';

    private array $files = [];

    private function context(bool $ssh = false): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        Process::fake();
        config(['saas.provisioning_enabled' => true, 'saas.ssh_enabled' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'password' => self::PASSWORD]);
        $owner = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->members()->attach($owner->id, ['role' => 'owner']);
        ServiceSubscription::create(['tenant_id' => $tenant->id, 'expires_at' => now()->addMonth(), 'payment_reference' => Str::uuid()]);
        $environment = Environment::create([
            'tenant_id' => $tenant->id, 'driver' => $ssh ? 'ssh' : 'simulation', 'hostname' => $tenant->slug.'.example.test',
            ...($ssh ? $this->target() : []), ...($ssh ? ['ip_address' => '8.8.4.4', 'server_id' => 'ssh:8.8.4.4'] : []),
            'release_url' => 'https://github.com/lehuunghi/NAC/releases/download/test/nac20-debian12-amd64-online.tar.gz', 'release_sha256' => str_repeat('a', 64),
        ]);
        $operation = Operation::create(['tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'requested_by' => $admin->id, 'idempotency_key' => Str::uuid()])->fresh();
        $this->actingAs($admin);

        return [$admin, $owner, $tenant, $environment, $operation];
    }

    private function target(): array
    {
        return ['ssh_host' => '8.8.4.4', 'ssh_port' => 22, 'ssh_user' => 'root', 'ssh_password' => 'Synthetic-Host-Password!', 'ssh_host_key' => 'ssh-ed25519 '.base64_encode("\x00\x00\x00\x0bssh-ed25519\x00\x00\x00\x20".str_repeat('b', 32))];
    }

    private function switchUser(User $user): void
    {
        $this->withSession(['password_hash_web' => $user->password])->actingAs($user);
    }

    private function configureAnsible(): void
    {
        $this->files[] = tempnam(sys_get_temp_dir(), 'ansible');
        config(['saas.ansible_binary' => end($this->files), 'saas.artifact_url' => 'https://github.com/lehuunghi/NAC/releases/download/test/nac20-debian12-amd64-online.tar.gz', 'saas.artifact_sha256' => str_repeat('a', 64)]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_cancelling_queued_operation_erases_secret_is_idempotent_and_fences_queued_job(): void
    {
        [, , , $environment, $operation] = $this->context(true);
        $oldJob = new ProvisionEnvironment($operation->id);
        $url = '/saas/api/v1/admin/operations/'.$operation->id.'/cancel';
        $this->postJson($url, ['confirmed' => true])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson($url, ['confirmed' => true])->assertOk();
        $oldJob->handle();
        $this->assertNull($environment->fresh()->ssh_password);
        $this->assertDatabaseHas('environments', ['id' => $environment->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('deployment_notifications', 2);
        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_running_operation_cannot_be_cancelled_and_cancelled_operation_cannot_start(): void
    {
        [, , , , $operation] = $this->context();
        $started = app(OperationLifecycle::class)->start($operation->id, 1);
        $this->assertNotNull($started);
        $this->postJson('/saas/api/v1/admin/operations/'.$operation->id.'/cancel', ['confirmed' => true])->assertConflict();
        $this->assertDatabaseHas('operations', ['id' => $operation->id, 'status' => 'running']);
        $this->assertDatabaseCount('deployment_notifications', 0);
    }

    public function test_cancel_requires_admin_and_explicit_confirmation(): void
    {
        [, $owner, , , $operation] = $this->context();
        $url = '/saas/api/v1/admin/operations/'.$operation->id.'/cancel';
        $this->postJson($url)->assertUnprocessable();
        $this->switchUser($owner);
        $this->postJson($url, ['confirmed' => true])->assertForbidden();
        $this->assertDatabaseHas('operations', ['id' => $operation->id, 'status' => 'queued']);
    }

    public function test_cancelled_ssh_requires_new_secret_and_old_attempt_cannot_change_restarted_operation(): void
    {
        [, , , $environment, $operation] = $this->context(true);
        $oldJob = new ProvisionEnvironment($operation->id);
        $base = '/saas/api/v1/admin/operations/'.$operation->id;
        $this->postJson($base.'/cancel', ['confirmed' => true])->assertOk();
        $this->postJson($base.'/retry', ['confirmed_reconciled' => true])->assertConflict();
        $this->postJson('/saas/api/v1/admin/environments/'.$environment->id.'/ssh-credentials', ['action' => 'replace', 'ssh_password' => 'Replacement-Host-Password!', 'current_password' => self::PASSWORD])->assertOk();
        $this->postJson($base.'/retry', ['confirmed_reconciled' => true])->assertAccepted()->assertJsonPath('attempt', 2);
        $oldJob->handle();
        $oldJob->failed(new \RuntimeException('old attempt timeout'));
        app(OperationLifecycle::class)->finish($operation, 'failed', 'WORKER_INTERRUPTED');
        $this->assertDatabaseHas('operations', ['id' => $operation->id, 'status' => 'queued', 'attempt' => 2]);
        $this->assertDatabaseHas('environments', ['id' => $environment->id, 'status' => 'queued']);
        Process::assertNothingRan();
        Queue::assertPushed(ProvisionEnvironment::class, 1);
    }

    public function test_console_retry_uses_same_environment_transition_and_attempt_increment(): void
    {
        [, , , $environment, $operation] = $this->context();
        $operation->update(['status' => 'failed']);
        $environment->update(['status' => 'failed']);
        $this->artisan('saas:retry', ['operation' => $operation->id])->expectsConfirmation('Đã kiểm tra tài nguyên, SSH host key và nguyên nhân lỗi?', 'yes')->assertSuccessful();
        $this->assertDatabaseHas('operations', ['id' => $operation->id, 'status' => 'queued', 'attempt' => 2]);
        $this->assertDatabaseHas('environments', ['id' => $environment->id, 'status' => 'queued']);
        Queue::assertPushed(ProvisionEnvironment::class, 1);
    }

    public function test_recovery_marks_stale_operation_failed_without_reinstalling_and_only_notifies_once(): void
    {
        [, , , $environment, $operation] = $this->context();
        $operation->update(['status' => 'running', 'started_at' => now()->subMinutes(51)]);
        $environment->update(['status' => 'provisioning']);
        $this->artisan('saas:recover-stale')->assertSuccessful();
        $this->artisan('saas:recover-stale')->assertSuccessful();
        $this->assertDatabaseHas('operations', ['id' => $operation->id, 'status' => 'failed', 'error_code' => 'WORKER_STALE']);
        $this->assertNotNull($operation->fresh()->finished_at);
        $this->assertDatabaseHas('environments', ['id' => $environment->id, 'status' => 'failed']);
        $this->assertDatabaseCount('deployment_notifications', 2);
        Queue::assertNothingPushed();
    }

    public function test_recovery_respects_live_worker_lock_and_recent_start(): void
    {
        [, , , , $operation] = $this->context();
        $operation->update(['status' => 'running', 'started_at' => now()->subMinutes(51)]);
        $lock = Cache::lock('provision:tenant:'.$operation->tenant_id, 100);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame(0, app(OperationLifecycle::class)->recoverStale());
        } finally {
            $lock->release();
        }
        $operation->update(['started_at' => now()->subMinutes(10)]);
        $this->assertSame(0, app(OperationLifecycle::class)->recoverStale());
        $this->assertDatabaseCount('deployment_notifications', 0);
    }

    public function test_completion_notifications_are_personal_and_follow_current_membership_and_admin_permission(): void
    {
        [$admin, $owner, $tenant, , $operation] = $this->context();
        (new ProvisionEnvironment($operation->id))->handle();
        $notice = DeploymentNotification::where('user_id', $owner->id)->firstOrFail();
        $this->switchUser($owner);
        $this->getJson('/saas/api/v1/notifications')->assertOk()->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'notifications.data');
        $this->postJson('/saas/api/v1/notifications/'.$notice->id.'/read')->assertOk();
        $this->getJson('/saas/api/v1/notifications')->assertJsonPath('unread_count', 0);
        $tenant->members()->detach($owner->id);
        $this->getJson('/saas/api/v1/notifications')->assertJsonCount(0, 'notifications.data');
        $this->postJson('/saas/api/v1/notifications/'.$notice->id.'/read')->assertNotFound();
        $this->switchUser($admin);
        $this->postJson('/saas/api/v1/notifications/'.$notice->id.'/read')->assertNotFound();
        $admin->forceFill(['is_platform_admin' => false])->save();
        $this->getJson('/saas/api/v1/notifications')->assertJsonCount(0, 'notifications.data');
    }

    public function test_preflight_failure_does_not_change_dns_or_install_packages_and_keeps_secret_hidden(): void
    {
        [, , , , $operation] = $this->context(true);
        $this->configureAnsible();
        $commands = [];
        Process::fake(function ($process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result(errorOutput: 'Synthetic-Host-Password!', exitCode: 1);
        });
        (new ProvisionEnvironment($operation->id))->handle();
        $this->assertDatabaseHas('operations', ['id' => $operation->id, 'status' => 'failed', 'error_code' => 'SSH_PREFLIGHT_FAILED']);
        $this->assertCount(1, $commands);
        $this->assertStringEndsWith('check-nac20-host.yml', $commands[0][3]);
        Http::assertNothingSent();
        $this->getJson('/saas/api/v1/notifications')->assertOk()->assertDontSee('Synthetic-Host-Password!');
    }

    public function test_manual_preflight_checks_only_and_requires_reauthentication_without_saving_credentials(): void
    {
        [$admin] = $this->context();
        $this->configureAnsible();
        $tenant = Tenant::factory()->create();
        $url = '/saas/api/v1/admin/customers/'.$tenant->id.'/check-ssh';
        $body = [...$this->target(), 'current_password' => self::PASSWORD];
        $this->postJson($url, [...$body, 'current_password' => 'wrong'])->assertUnprocessable();
        $this->postJson($url, $body)->assertOk()->assertDontSee('Synthetic-Host-Password!');
        $this->assertDatabaseMissing('environments', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('operations', ['tenant_id' => $tenant->id]);
        Http::assertNothingSent();
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'installation.ssh_checked', 'tenant_id' => $tenant->id, 'user_id' => $admin->id]);
    }
}
