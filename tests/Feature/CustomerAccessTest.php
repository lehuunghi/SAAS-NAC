<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class CustomerAccessTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $role = 'owner'): array
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        $tenant->members()->attach($owner->id, ['role' => $role]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'password' => 'Access-Admin-Password!']);
        $environment = Environment::create(['tenant_id' => $tenant->id, 'hostname' => 'company.nac.example.test', 'driver' => 'ssh', 'status' => 'ready', 'handed_over_at' => now()]);

        return [$tenant, $owner, $admin, $environment];
    }

    private function body(): array
    {
        return ['access_url' => 'https://company.nac.example.test:1443/', 'access_account' => 'company-operator', 'access_instructions' => 'Nhận mật khẩu qua kênh riêng đã thống nhất.', 'current_password' => 'Access-Admin-Password!'];
    }

    private function asUser(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
    }

    public function test_admin_publishes_access_for_owner_after_handover_without_exposing_it_in_environment_lists(): void
    {
        [$tenant, $owner, $admin, $environment] = $this->context();
        $this->asUser($owner);
        $this->getJson('/saas/api/v1/tenants/'.$tenant->id.'/access')->assertOk()->assertJsonPath('available', false)->assertJsonPath('account', null);
        $this->asUser($admin);
        $this->postJson('/saas/api/v1/admin/environments/'.$environment->id.'/access', $this->body())->assertOk();
        $this->getJson('/saas/api/v1/admin/environments/'.$environment->id.'/access')->assertOk()->assertJsonPath('access_account', 'company-operator');
        $this->assertDatabaseHas('environments', ['id' => $environment->id, 'access_account' => 'company-operator']);
        $this->assertDatabaseHas('admin_audit_events', ['user_id' => $admin->id, 'action' => 'environment.access_published']);
        $this->asUser($owner);
        $this->getJson('/saas/api/v1/tenants/'.$tenant->id.'/access')->assertOk()->assertJsonPath('available', true)->assertJsonPath('url', $this->body()['access_url'])->assertJsonPath('account', 'company-operator')->assertJsonMissingPath('password');
        $this->getJson('/saas/api/v1/tenants/'.$tenant->id)->assertOk()->assertJsonMissingPath('environments.0.access_account')->assertJsonMissingPath('environments.0.access_url')->assertJsonMissingPath('environments.0.access_instructions');
    }

    public function test_guests_other_companies_and_read_only_roles_cannot_read_published_account_details(): void
    {
        [$tenant, $owner, $admin, $environment] = $this->context('viewer');
        $this->asUser($admin);
        $this->postJson('/saas/api/v1/admin/environments/'.$environment->id.'/access', $this->body())->assertOk();
        $this->postJson('/saas/api/v1/logout')->assertNoContent();
        $url = '/saas/api/v1/tenants/'.$tenant->id.'/access';
        $this->getJson($url)->assertUnauthorized();
        $this->asUser($owner);
        $this->getJson($url)->assertForbidden();
        $tenant->members()->updateExistingPivot($owner->id, ['role' => 'operator']);
        $this->getJson($url)->assertForbidden();
        $this->asUser(User::factory()->create());
        $this->getJson($url)->assertForbidden();
        $this->getJson('/saas/api/v1/admin/environments/'.$environment->id.'/access')->assertForbidden();
        $this->postJson('/saas/api/v1/admin/environments/'.$environment->id.'/access', $this->body())->assertForbidden();
    }

    public function test_publication_requires_admin_reauthentication_and_rejects_external_or_credential_urls(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [, , $admin, $environment] = $this->context();
        $this->asUser($admin);
        $url = '/saas/api/v1/admin/environments/'.$environment->id.'/access';
        $this->postJson($url, [...$this->body(), 'current_password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        foreach (['http://company.nac.example.test', 'https://attacker.example.test', 'https://name:secret@company.nac.example.test', 'https://company.nac.example.test/?token=secret', 'javascript:alert(1)'] as $invalid) {
            $this->postJson($url, [...$this->body(), 'access_url' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('access_url');
        }
        $this->postJson($url, [...$this->body(), 'password' => 'Do-not-store-this'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertNull($environment->fresh()->access_url);
    }

    public function test_access_publication_attempts_are_rate_limited(): void
    {
        [, , $admin, $environment] = $this->context();
        $this->asUser($admin);
        $url = '/saas/api/v1/admin/environments/'.$environment->id.'/access';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($url)->assertUnprocessable();
        }
        $this->postJson($url)->assertTooManyRequests();
    }

    public function test_access_is_not_available_for_unaccepted_simulated_or_suspended_environments(): void
    {
        [$tenant, $owner, $admin, $environment] = $this->context();
        $this->asUser($admin);
        $url = '/saas/api/v1/admin/environments/'.$environment->id.'/access';
        foreach ([
            ['status' => 'awaiting_configuration', 'driver' => 'ssh', 'handed_over_at' => null],
            ['status' => 'ready', 'driver' => 'simulation', 'handed_over_at' => now()],
        ] as $state) {
            $environment->forceFill($state)->save();
            $this->postJson($url, $this->body())->assertConflict();
        }
        $environment->forceFill(['status' => 'ready', 'driver' => 'ssh', 'handed_over_at' => now()])->save();
        $this->postJson($url, $this->body())->assertOk();
        $tenant->update(['status' => 'suspended']);
        $this->asUser($owner);
        $this->getJson('/saas/api/v1/tenants/'.$tenant->id.'/access')->assertForbidden();
    }
}
