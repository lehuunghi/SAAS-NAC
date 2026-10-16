<?php

namespace Tests\Feature;

use App\Jobs\ProvisionEnvironment;
use App\Models\Environment;
use App\Models\Order;
use App\Models\QuoteRequest;
use App\Models\ServiceSubscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SshInstallationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PASSWORD = 'Admin-Test-Password-2026!';

    private const SSH_PASSWORD = ' Synthetic-SSH-Password-$`! ';

    private array $files = [];

    private function setupCustomer(): array
    {
        Queue::fake();
        config([
            'saas.provisioning_enabled' => true, 'saas.ssh_enabled' => true,
            'saas.artifact_url' => 'https://github.com/lehuunghi/NAC/releases/download/test/nac20-debian12-amd64-online.tar.gz',
            'saas.artifact_sha256' => str_repeat('a', 64),
        ]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'password' => self::ADMIN_PASSWORD]);
        $tenant = Tenant::factory()->create();
        ServiceSubscription::create(['tenant_id' => $tenant->id, 'payment_reference' => Str::uuid(), 'expires_at' => now()->addMonth()]);
        $this->actingAs($admin);

        return [$admin, $tenant];
    }

    private function body(array $overrides = []): array
    {
        return [...[
            'driver' => 'ssh', 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true, 'dedicated_server' => true,
            'current_password' => self::ADMIN_PASSWORD, 'ssh_host' => '8.8.4.4', 'ssh_port' => 2222, 'ssh_user' => 'root',
            'ssh_password' => self::SSH_PASSWORD,
            'ssh_host_key' => 'ssh-ed25519 '.base64_encode("\x00\x00\x00\x0bssh-ed25519\x00\x00\x00\x20".str_repeat('a', 32)),
        ], ...$overrides];
    }

    private function requestInstall(Tenant $tenant, array $body): TestResponse
    {
        return $this->postJson('/saas/api/v1/admin/customers/'.$tenant->id.'/install', $body);
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

    public function test_admin_queues_ssh_installation_with_encrypted_secret_and_idempotent_request(): void
    {
        [, $tenant] = $this->setupCustomer();
        $body = $this->body();
        $response = $this->requestInstall($tenant, $body)->assertAccepted()->assertDontSee(self::SSH_PASSWORD);
        $this->requestInstall($tenant, $body)->assertAccepted()->assertJsonPath('id', $response->json('id'));
        $environment = Environment::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(self::SSH_PASSWORD, $environment->ssh_password);
        $ciphertext = DB::table('environments')->where('id', $environment->id)->value('ssh_password');
        $this->assertNotSame(self::SSH_PASSWORD, $ciphertext);
        $this->getJson('/saas/api/v1/admin/installations')->assertOk()->assertJsonMissingPath('environments.data.0.ssh_password')->assertDontSee(self::SSH_PASSWORD)->assertDontSee($ciphertext);
        Queue::assertPushed(ProvisionEnvironment::class, 1);
    }

    public function test_ssh_installation_requires_password_reauthentication_confirmation_and_valid_target(): void
    {
        [, $tenant] = $this->setupCustomer();
        foreach ([['current_password' => 'wrong'], ['dedicated_server' => false], ['ssh_host' => '127.0.0.1'], ['ssh_user' => 'root;id'], ['ssh_host_key' => 'untrusted']] as $invalid) {
            $this->requestInstall($tenant, $this->body($invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('environments', 0);
        Queue::assertNothingPushed();
    }

    public function test_ssh_installation_cannot_bypass_live_switch_or_subscription(): void
    {
        [, $tenant] = $this->setupCustomer();
        config(['saas.ssh_enabled' => false]);
        $this->requestInstall($tenant, $this->body())->assertConflict();
        config(['saas.ssh_enabled' => true, 'saas.provisioning_enabled' => false]);
        $this->requestInstall($tenant, $this->body())->assertConflict();
        config(['saas.provisioning_enabled' => true]);
        ServiceSubscription::where('tenant_id', $tenant->id)->update(['expires_at' => now()->subDay()]);
        $this->requestInstall($tenant, $this->body())->assertConflict();
        Queue::assertNothingPushed();
    }

    public function test_two_companies_cannot_install_on_the_same_server(): void
    {
        [, $tenant] = $this->setupCustomer();
        $this->requestInstall($tenant, $this->body())->assertAccepted();
        $other = Tenant::factory()->create();
        ServiceSubscription::create(['tenant_id' => $other->id, 'payment_reference' => Str::uuid(), 'expires_at' => now()->addMonth()]);
        $this->requestInstall($other, $this->body())->assertConflict();
        $this->assertDatabaseCount('environments', 1);
    }

    public function test_customer_cannot_use_admin_ssh_api_or_retrieve_server_password(): void
    {
        [, $tenant] = $this->setupCustomer();
        $this->requestInstall($tenant, $this->body())->assertAccepted();
        $owner = User::factory()->create();
        $tenant->members()->attach($owner->id, ['role' => 'owner']);
        $this->withSession(['password_hash_web' => $owner->password])->actingAs($owner);
        $this->requestInstall($tenant, $this->body())->assertForbidden();
        $this->getJson('/saas/api/v1/tenants/'.$tenant->id)->assertOk()->assertJsonMissingPath('environments.0.ssh_password')->assertDontSee(self::SSH_PASSWORD);
    }

    public function test_worker_installs_existing_server_without_creating_vm_and_erases_password_and_temp_files(): void
    {
        [, $tenant] = $this->setupCustomer();
        $operation = $this->requestInstall($tenant, $this->body())->assertAccepted()->json('id');
        $this->files[] = tempnam(sys_get_temp_dir(), 'ansible');
        config(['saas.ansible_binary' => $this->files[0], 'saas.cloudflare.token' => 'synthetic-token', 'saas.cloudflare.zone_id' => str_repeat('a', 32)]);
        Http::preventStrayRequests();
        Http::fake(['https://api.cloudflare.com/*' => Http::sequence()->push(['result' => []])->push(['success' => true])]);
        $temporaryFiles = [];
        Process::fake(function ($process) use (&$temporaryFiles) {
            $this->assertIsArray($process->command);
            $this->assertStringNotContainsString(self::SSH_PASSWORD, json_encode($process->command));
            $inventory = json_decode(file_get_contents($process->command[2]), true, 512, JSON_THROW_ON_ERROR)['all']['hosts']['nac20'];
            $this->assertSame(self::SSH_PASSWORD, $inventory['ansible_password']);
            $this->assertSame(2222, $inventory['ansible_port']);
            $this->assertFalse($inventory['nac_wait_cloud_init']);
            $this->assertStringContainsString('StrictHostKeyChecking=yes', $inventory['ansible_ssh_common_args']);
            $temporaryFiles = glob(dirname($process->command[2]).'/nac20-*');

            return Process::result();
        });

        (new ProvisionEnvironment($operation))->handle();

        $this->assertDatabaseHas('operations', ['id' => $operation, 'status' => 'completed', 'error_code' => null]);
        $this->assertDatabaseHas('environments', ['tenant_id' => $tenant->id, 'status' => 'awaiting_configuration', 'ssh_password' => null]);
        foreach ($temporaryFiles as $file) {
            $this->assertFileDoesNotExist($file);
        }
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'hetzner'));
    }

    public function test_failed_installation_can_replace_or_forget_password_without_automatically_retrying(): void
    {
        [, $tenant] = $this->setupCustomer();
        $this->requestInstall($tenant, $this->body())->assertAccepted();
        $environment = Environment::where('tenant_id', $tenant->id)->firstOrFail();
        $url = '/saas/api/v1/admin/environments/'.$environment->id.'/ssh-credentials';
        $body = ['action' => 'replace', 'current_password' => self::ADMIN_PASSWORD, 'ssh_password' => 'New-Synthetic-Password'];
        $this->postJson($url, $body)->assertConflict();
        $environment->update(['status' => 'failed']);
        $this->postJson($url, [...$body, 'current_password' => 'wrong'])->assertUnprocessable();
        $this->postJson($url, $body)->assertOk()->assertDontSee('New-Synthetic-Password');
        $this->assertSame('New-Synthetic-Password', $environment->fresh()->ssh_password);
        $this->postJson($url, ['action' => 'forget', 'current_password' => self::ADMIN_PASSWORD])->assertOk();
        $this->assertNull($environment->fresh()->ssh_password);
        Queue::assertPushed(ProvisionEnvironment::class, 1);
    }

    public function test_worker_failure_keeps_encrypted_password_for_retry_but_does_not_save_process_output(): void
    {
        [, $tenant] = $this->setupCustomer();
        $operation = $this->requestInstall($tenant, $this->body())->assertAccepted()->json('id');
        $this->files[] = tempnam(sys_get_temp_dir(), 'ansible');
        config(['saas.ansible_binary' => $this->files[0], 'saas.cloudflare.token' => 'synthetic-token', 'saas.cloudflare.zone_id' => str_repeat('a', 32)]);
        Http::fake(['https://api.cloudflare.com/*' => Http::sequence()->push(['result' => []])->push(['success' => true])]);
        $inventoryFile = null;
        Process::fake(function ($process) use (&$inventoryFile) {
            $inventoryFile = $process->command[2];

            return Process::result(errorOutput: 'Sensitive process output '.self::SSH_PASSWORD, exitCode: 1);
        });

        (new ProvisionEnvironment($operation))->handle();

        $this->assertDatabaseHas('operations', ['id' => $operation, 'status' => 'failed', 'error_code' => 'ANSIBLE_FAILED']);
        $this->assertSame(self::SSH_PASSWORD, Environment::where('tenant_id', $tenant->id)->firstOrFail()->ssh_password);
        $this->assertFileDoesNotExist($inventoryFile);
        $this->getJson('/saas/api/v1/admin/installations')->assertOk()->assertDontSee(self::SSH_PASSWORD)->assertDontSee('Sensitive process output');
    }

    public function test_new_owner_creation_requires_admin_password_and_cannot_reuse_existing_account_email(): void
    {
        [$admin] = $this->setupCustomer();
        $body = ['name' => 'Customer', 'slug' => 'new-customer', 'create_owner' => true, 'owner_email' => 'new-owner@example.test', 'owner_name' => 'Owner', 'owner_password' => 'New-Owner-Password-2026!', 'owner_password_confirmation' => 'New-Owner-Password-2026!'];
        $this->postJson('/saas/api/v1/admin/customers', $body)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/saas/api/v1/admin/customers', [...$body, 'current_password' => self::ADMIN_PASSWORD, 'owner_email' => $admin->email])->assertUnprocessable()->assertJsonValidationErrors('owner_email');
        $this->assertDatabaseMissing('users', ['email' => 'new-owner@example.test']);
    }

    public function test_admin_creates_customer_and_new_owner_without_returning_or_granting_platform_credentials(): void
    {
        $this->setupCustomer();
        $response = $this->postJson('/saas/api/v1/admin/customers', [
            'name' => 'New customer', 'slug' => 'new-customer', 'create_owner' => true, 'owner_email' => 'new-owner@example.test', 'owner_name' => 'New owner',
            'owner_password' => 'New-Owner-Password-2026!', 'owner_password_confirmation' => 'New-Owner-Password-2026!', 'current_password' => self::ADMIN_PASSWORD,
            'is_platform_admin' => true,
        ])->assertCreated()->assertDontSee('New-Owner-Password-2026!');
        $owner = User::where('email', 'new-owner@example.test')->firstOrFail();
        $this->assertFalse($owner->is_platform_admin);
        $this->assertTrue(Hash::check('New-Owner-Password-2026!', $owner->password));
        $this->assertDatabaseHas('memberships', ['tenant_id' => $response->json('id'), 'user_id' => $owner->id, 'role' => 'owner']);
    }

    public function test_payment_can_activate_subscription_without_creating_environment_before_admin_ssh_choice(): void
    {
        [, $tenant] = $this->setupCustomer();
        $quote = QuoteRequest::factory()->create(['tenant_id' => $tenant->id]);
        $order = Order::factory()->create(['tenant_id' => $tenant->id, 'quote_request_id' => $quote->id, 'status' => 'pending_payment', 'amount_vnd' => 1000000]);
        $this->postJson('/saas/api/v1/admin/orders/'.$order->id.'/verify-payment', [
            'reference' => 'TEST-SSH-DEFERRED', 'amount_vnd' => 1000000, 'confirmed_received' => true, 'defer_installation' => true,
        ])->assertOk()->assertJsonPath('status', 'paid');
        $this->assertDatabaseCount('environments', 0);
        Queue::assertNothingPushed();
    }
}
