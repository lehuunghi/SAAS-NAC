<?php

namespace Tests\Feature;

use App\Jobs\ProvisionEnvironment;
use App\Models\Environment;
use App\Models\Order;
use App\Models\QuoteRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }

    private function company(string $role = 'owner'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['password' => 'Test-Owner-Password-2026!']);
        $tenant->members()->attach($user->id, ['role' => $role]);

        return [$tenant, $user];
    }

    public function test_company_admin_is_not_platform_admin_and_cannot_call_any_admin_list(): void
    {
        $this->getJson('/saas/api/v1/admin/overview')->assertUnauthorized();
        [, $user] = $this->company('admin');
        $this->actingAs($user);
        foreach (['overview', 'customers', 'sales', 'installations', 'users', 'tickets', 'settings', 'audit'] as $path) {
            $this->getJson('/saas/api/v1/admin/'.$path)->assertForbidden();
        }
        $this->postJson('/saas/api/v1/admin/quotes/1/issue')->assertForbidden();
        $this->postJson('/saas/api/v1/admin/orders/1/verify-payment')->assertForbidden();
    }

    public function test_registration_cannot_grant_platform_rights(): void
    {
        $this->postJson('/saas/api/v1/register', ['name' => 'X', 'company' => 'X company', 'email' => 'attacker@example.test', 'password' => 'Long-Password-For-Test!', 'password_confirmation' => 'Long-Password-For-Test!', 'is_platform_admin' => true])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_platform_role_revocation_and_user_lock_apply_to_existing_sessions(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson('/saas/api/v1/admin/overview')->assertOk();
        $admin->forceFill(['is_platform_admin' => false])->save();
        $this->getJson('/saas/api/v1/admin/overview')->assertForbidden();
        $admin->forceFill(['is_active' => false])->save();
        $this->getJson('/saas/api/v1/me')->assertUnauthorized();
    }

    public function test_admin_can_issue_and_reconcile_an_order_with_an_audited_actor(): void
    {
        Queue::fake();
        $admin = $this->admin();
        [$tenant, $owner] = $this->company();
        $quote = QuoteRequest::factory()->create(['tenant_id' => $tenant->id, 'requested_by' => $owner->id]);
        $response = $this->actingAs($admin)->postJson('/saas/api/v1/admin/quotes/'.$quote->id.'/issue', ['amount_vnd' => 1000000, 'service_days' => 30, 'terms' => 'Điều khoản báo giá chỉ dành cho kiểm thử.'])->assertCreated();
        $order = Order::findOrFail($response->json('id'));
        $order->update(['status' => 'pending_payment']);
        $url = '/saas/api/v1/admin/orders/'.$order->id.'/verify-payment';
        $this->postJson($url, ['reference' => 'ADMIN-PAY-TEST', 'amount_vnd' => 1000000])->assertUnprocessable();
        $this->postJson($url, ['reference' => 'ADMIN-PAY-TEST', 'amount_vnd' => 1000000, 'confirmed_received' => true])->assertOk()->assertJsonPath('status', 'paid');
        $this->assertDatabaseHas('admin_audit_events', ['user_id' => $admin->id, 'tenant_id' => $tenant->id, 'action' => 'payment.verified']);
        $this->assertDatabaseHas('audit_events', ['user_id' => $admin->id, 'action' => 'payment.verified_by_admin']);
    }

    public function test_admin_can_install_for_an_unrelated_company_idempotently(): void
    {
        Queue::fake();
        $admin = $this->admin();
        [$tenant] = $this->company();
        config(['saas.artifact_url' => 'https://github.com/lehuunghi/NAC/releases/download/test/nac20-debian12-amd64-online.tar.gz', 'saas.artifact_sha256' => str_repeat('a', 64)]);
        $body = ['driver' => 'simulation', 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true, 'notes' => 'Mô phỏng kiểm thử.'];
        $url = '/saas/api/v1/admin/customers/'.$tenant->id.'/install';
        $id = $this->actingAs($admin)->postJson($url, $body)->assertAccepted()->json('id');
        $this->postJson($url, $body)->assertAccepted()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('environments', 1);
        $this->assertDatabaseHas('environments', ['tenant_id' => $tenant->id, 'release_sha256' => str_repeat('a', 64)]);
        (new ProvisionEnvironment($id))->handle();
        $this->assertDatabaseHas('environments', ['tenant_id' => $tenant->id, 'status' => 'simulated']);
        $this->assertDatabaseHas('operations', ['id' => $id, 'requested_by' => $admin->id, 'status' => 'completed']);
    }

    public function test_admin_cannot_bypass_live_enablement_or_subscription(): void
    {
        [$tenant] = $this->company();
        $body = ['driver' => 'hetzner', 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true];
        $url = '/saas/api/v1/admin/customers/'.$tenant->id.'/install';
        $this->actingAs($this->admin())->postJson($url, $body)->assertConflict();
        config(['saas.provisioning_enabled' => true]);
        $this->postJson($url, $body)->assertConflict();
        $this->assertDatabaseCount('environments', 0);
    }

    public function test_handover_requires_real_installed_environment_and_complete_evidence(): void
    {
        [$tenant] = $this->company();
        $environment = Environment::create(['tenant_id' => $tenant->id, 'hostname' => 'demo.example.test', 'driver' => 'simulation', 'status' => 'awaiting_configuration']);
        $url = '/saas/api/v1/admin/environments/'.$environment->id.'/handover';
        $this->actingAs($this->admin())->postJson($url, [])->assertUnprocessable();
        $body = ['database_checked' => true, 'tls_checked' => true, 'connector_checked' => true, 'radius_checked' => true, 'backup_checked' => true, 'evidence' => 'Biên bản thử nghiệm chỉ dùng làm dữ liệu kiểm thử.'];
        $this->postJson($url, $body)->assertConflict();
        $environment->update(['driver' => 'hetzner', 'server_id' => '42', 'ip_address' => '8.8.4.4']);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('status', 'ready');
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'installation.handed_over', 'tenant_id' => $tenant->id]);
    }

    public function test_admin_settings_never_expose_credentials_or_ssh_paths(): void
    {
        config(['saas.hcloud.token' => 'SENTINEL_PROVIDER_SECRET', 'saas.cloudflare.token' => 'SENTINEL_DNS_SECRET', 'saas.ssh_key' => '/secret/private-key']);
        $this->actingAs($this->admin())->getJson('/saas/api/v1/admin/settings')->assertOk()->assertJsonFragment(['hcloud.token' => true])->assertDontSee('SENTINEL_PROVIDER_SECRET')->assertDontSee('SENTINEL_DNS_SECRET')->assertDontSee('/secret/private-key');
    }

    public function test_user_management_cannot_create_admins_or_lock_platform_admin(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->actingAs($admin)->postJson('/saas/api/v1/admin/users/'.$user->id, ['is_active' => true, 'is_platform_admin' => true])->assertUnprocessable();
        $this->postJson('/saas/api/v1/admin/users/'.$admin->id, ['is_active' => false])->assertConflict();
        $this->postJson('/saas/api/v1/admin/users/'.$user->id, ['is_active' => false])->assertOk();
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_only_owner_can_assign_company_roles_and_cannot_overwrite_owner(): void
    {
        [$tenant, $owner] = $this->company();
        $other = User::factory()->create();
        $url = '/saas/api/v1/tenants/'.$tenant->id.'/members';
        $body = ['email' => $other->email, 'role' => 'admin', 'current_password' => 'Test-Owner-Password-2026!'];
        $this->actingAs($owner)->postJson($url, [...$body, 'current_password' => 'wrong'])->assertUnprocessable();
        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, [...$body, 'email' => $owner->email])->assertConflict();
        $this->assertFalse($other->fresh()->is_platform_admin);
        $this->actingAs($other)->postJson($url, $body)->assertForbidden();
        $this->getJson('/saas/api/v1/admin/overview')->assertForbidden();
    }

    public function test_customer_profile_cannot_change_tenant_status_or_access_other_company(): void
    {
        [$a, $owner] = $this->company();
        [$b] = $this->company();
        $this->actingAs($owner)->postJson('/saas/api/v1/tenants/'.$a->id.'/profile', ['name' => 'Updated', 'status' => 'active'])->assertUnprocessable();
        $this->postJson('/saas/api/v1/tenants/'.$b->id.'/profile', ['name' => 'Intrusion'])->assertForbidden();
        $this->postJson('/saas/api/v1/tenants/'.$a->id.'/profile', ['name' => 'Tên công ty cập nhật'])->assertOk();
        $this->assertDatabaseHas('tenants', ['id' => $a->id, 'name' => 'Tên công ty cập nhật']);
    }
}
