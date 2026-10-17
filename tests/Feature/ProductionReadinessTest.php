<?php

namespace Tests\Feature;

use App\Jobs\ProvisionEnvironment;
use App\Models\Environment;
use App\Models\Operation;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ProductionReadiness;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = config('database.default');
    }

    protected function tearDown(): void
    {
        config(['database.default' => $this->originalConnection]);
        parent::tearDown();
    }

    public function test_production_rejects_simulation_from_admin_and_customer_even_with_live_enabled(): void
    {
        Queue::fake();
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['saas.driver' => 'simulation', 'saas.provisioning_enabled' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $tenant = Tenant::factory()->create();
        $tenant->members()->attach($admin->id, ['role' => 'owner']);
        $this->actingAs($admin)->getJson('/saas/api/v1/admin/settings')->assertOk()->assertJsonPath('simulation_enabled', false);
        $this->getJson('/saas/api/v1/tenants/'.$tenant->id)->assertOk()->assertJsonPath('can_request_environment', false);
        $this->postJson('/saas/api/v1/admin/customers/'.$tenant->id.'/install', ['driver' => 'simulation', 'idempotency_key' => Str::uuid(), 'confirmed' => true])->assertConflict();
        $this->postJson('/saas/api/v1/tenants/'.$tenant->id.'/environments', ['idempotency_key' => Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('environments', 0);
        Queue::assertNothingPushed();
    }

    public function test_production_payment_activation_never_automatically_creates_simulated_environment(): void
    {
        Queue::fake();
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['saas.driver' => 'simulation']);
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $order = Order::factory()->create(['status' => 'pending_payment']);
        $this->actingAs($admin)->postJson('/saas/api/v1/admin/orders/'.$order->id.'/verify-payment', [
            'reference' => 'SYNTHETIC-PRODUCTION-TEST', 'amount_vnd' => $order->amount_vnd, 'confirmed_received' => true,
        ])->assertOk()->assertJsonPath('status', 'paid');
        $this->assertDatabaseHas('service_subscriptions', ['tenant_id' => $order->tenant_id, 'status' => 'active']);
        $this->assertDatabaseCount('environments', 0);
        Queue::assertNothingPushed();
    }

    public function test_legacy_simulation_job_is_failed_and_cannot_be_retried_on_production(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $tenant = Tenant::factory()->create();
        $environment = Environment::create(['tenant_id' => $tenant->id, 'hostname' => $tenant->slug.'.example.test', 'driver' => 'simulation']);
        $operation = Operation::create(['tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'requested_by' => $admin->id, 'idempotency_key' => Str::uuid()]);
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
        (new ProvisionEnvironment($operation->id))->handle();
        $this->assertDatabaseHas('operations', ['id' => $operation->id, 'status' => 'failed', 'error_code' => 'SIMULATION_DISABLED']);
        $this->assertDatabaseHas('environments', ['id' => $environment->id, 'status' => 'failed']);
        $this->actingAs($admin)->postJson('/saas/api/v1/admin/operations/'.$operation->id.'/retry', ['confirmed_reconciled' => true])->assertConflict();
    }

    public function test_preflight_command_returns_failure_for_incomplete_configuration_without_exposing_secrets(): void
    {
        config(['saas.cloudflare.token' => 'secret-cloud-token', 'mail.mailers.smtp.password' => 'secret-smtp-password']);
        $this->assertSame(1, Artisan::call('saas:production-check', ['--connections' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('CHƯA ĐẠT', $output);
        $this->assertStringNotContainsString('secret-cloud-token', $output);
        $this->assertStringNotContainsString('secret-smtp-password', $output);
    }

    public function test_configuration_check_accepts_explicit_live_settings_but_rejects_preview_artifact_and_unsafe_cookies(): void
    {
        $this->configureProduction();
        $this->assertSame(0, Artisan::call('saas:production-check'));
        config(['saas.artifact_url' => 'https://github.com/lehuunghi/NAC/releases/download/nac20-16.0.0-preview.1/nac20-debian12-amd64-online.tar.gz', 'session.secure' => false]);
        $checks = app(ProductionReadiness::class)->checks();
        $this->assertFalse($checks['artifact']['passed']);
        $this->assertFalse($checks['cookies']['passed']);
        $this->assertSame(1, Artisan::call('saas:production-check'));
    }

    public function test_real_mysql_and_redis_connection_checks_in_ci(): void
    {
        if (config('database.default') !== 'mysql' || ! env('SAAS_REDIS_TESTS')) {
            $this->markTestSkipped('Requires CI MySQL and Redis.');
        }
        $this->configureProduction();
        $checks = app(ProductionReadiness::class)->checks(true);
        $this->assertTrue($checks['mysql_connection']['passed']);
        $this->assertTrue($checks['redis_connection']['passed']);
        $this->assertTrue($checks['simulation_data']['passed']);
        $tenant = Tenant::factory()->create();
        Environment::create(['tenant_id' => $tenant->id, 'hostname' => $tenant->slug.'.example.test', 'driver' => 'simulation']);
        $this->assertFalse(app(ProductionReadiness::class)->checks(true)['simulation_data']['passed']);
    }

    public function test_production_requests_require_csrf_and_secure_session_cookie(): void
    {
        $this->app['env'] = 'production';
        config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/saas/api/v1/profile', ['name' => 'Forged update'])->assertStatus(419);
        $this->assertNotSame('Forged update', $user->fresh()->name);
        $token = Str::random(40);
        $response = $this->withSession(['_token' => $token])->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/saas/api/v1/profile', ['name' => 'Verified update'])->assertOk();
        $this->assertSame('Verified update', $user->fresh()->name);
        $cookie = $response->getCookie(config('session.cookie'), false);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    private function configureProduction(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.debug' => false, 'app.url' => 'https://portal.20.com.vn',
            'database.default' => 'mysql', 'database.connections.mysql.charset' => 'utf8mb4', 'database.connections.mysql.engine' => 'InnoDB',
            'session.driver' => 'redis', 'session.secure' => true, 'session.http_only' => true, 'session.encrypt' => true, 'session.same_site' => 'lax',
            'cache.default' => 'redis', 'queue.default' => 'redis', 'queue.connections.redis.retry_after' => 2700,
            'saas.driver' => 'manual', 'saas.provisioning_enabled' => true, 'saas.ssh_enabled' => true,
            'saas.domain' => 'nac.20.com.vn', 'saas.cloudflare.token' => 'synthetic-token', 'saas.cloudflare.zone_id' => str_repeat('a', 32),
            'saas.artifact_url' => 'https://github.com/lehuunghi/NAC/releases/download/nac20-16.0.0/nac20-debian12-amd64-online.tar.gz',
            'saas.artifact_sha256' => str_repeat('a', 64), 'saas.ansible_binary' => PHP_BINARY,
            'auth.password_recovery_enabled' => true, 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test',
            'commerce.bank' => ['enabled' => true, 'name' => 'Synthetic bank', 'account_number' => 'TEST-ONLY', 'account_holder' => 'Synthetic holder'],
        ]);
    }
}
