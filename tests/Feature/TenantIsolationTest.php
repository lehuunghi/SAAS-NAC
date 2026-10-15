<?php

namespace Tests\Feature;

use App\Jobs\ProvisionEnvironment;
use App\Models\Environment;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug, string $role = 'owner'): array
    {
        $tenant = Tenant::create(['name' => 'Công ty '.$slug, 'slug' => $slug]);
        $user = User::factory()->create();
        $tenant->members()->attach($user->id, ['role' => $role]);

        return [$tenant, $user];
    }

    public function test_unauthenticated_requests_cannot_read_companies(): void
    {
        $this->getJson('/saas/api/v1/tenants')->assertUnauthorized();
    }

    public function test_company_list_and_details_are_scoped_to_membership(): void
    {
        [$a, $user] = $this->company('alpha');
        [$b] = $this->company('bravo');
        Site::create(['tenant_id' => $b->id, 'name' => 'Bí mật Bravo', 'code' => 'hanoi']);
        $this->actingAs($user)->getJson('/saas/api/v1/tenants')->assertJsonCount(1)->assertJsonPath('0.id', $a->id);
        $this->getJson('/saas/api/v1/tenants/'.$b->id)->assertForbidden()->assertDontSee('Bí mật Bravo');
        $this->postJson('/saas/api/v1/tenants/'.$b->id.'/sites', ['name' => 'X', 'code' => 'xx'])->assertForbidden();
        $this->postJson('/saas/api/v1/tenants/'.$b->id.'/environments', ['idempotency_key' => Str::uuid()])->assertForbidden();
    }

    public function test_viewer_cannot_write_even_with_forged_payload(): void
    {
        [$tenant, $user] = $this->company('readonly', 'viewer');
        $this->actingAs($user)->postJson('/saas/api/v1/tenants/'.$tenant->id.'/sites', ['name' => 'X', 'code' => 'xx'])->assertForbidden();
        $this->postJson('/saas/api/v1/tenants/'.$tenant->id.'/environments', ['idempotency_key' => Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('sites', 0);
    }

    public function test_membership_revocation_is_checked_on_every_request(): void
    {
        [$tenant, $user] = $this->company('revoked');
        $this->actingAs($user)->getJson('/saas/api/v1/tenants/'.$tenant->id)->assertOk();
        $tenant->members()->detach($user->id);
        $this->getJson('/saas/api/v1/tenants/'.$tenant->id)->assertForbidden();
    }

    public function test_site_codes_can_repeat_across_companies_but_payload_cannot_override_owner(): void
    {
        [$a, $ua] = $this->company('alpha');
        [$b, $ub] = $this->company('bravo');
        foreach ([[$a, $ua], [$b, $ub]] as [$t, $u]) {
            $this->actingAs($u)->postJson('/saas/api/v1/tenants/'.$t->id.'/sites', ['name' => 'Hà Nội', 'code' => 'hanoi'])->assertCreated()->assertJsonPath('tenant_id', $t->id);
        }
        $this->actingAs($ua)->postJson('/saas/api/v1/tenants/'.$a->id.'/sites', ['tenant_id' => $b->id, 'name' => 'X', 'code' => 'xx'])->assertUnprocessable();
        $this->assertDatabaseCount('sites', 2);
    }

    public function test_environment_rejects_site_from_another_company(): void
    {
        [$a, $user] = $this->company('alpha');
        [$b] = $this->company('bravo');
        $site = Site::create(['tenant_id' => $b->id, 'name' => 'B', 'code' => 'bb']);
        $this->actingAs($user)->postJson('/saas/api/v1/tenants/'.$a->id.'/environments', ['site_id' => $site->id, 'idempotency_key' => Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('environments', 0);
    }

    public function test_database_foreign_key_also_blocks_cross_company_site(): void
    {
        [$a] = $this->company('alpha');
        [$b] = $this->company('bravo');
        $site = Site::create(['tenant_id' => $b->id, 'name' => 'B', 'code' => 'bb']);
        $this->expectException(QueryException::class);
        Environment::create(['tenant_id' => $a->id, 'site_id' => $site->id, 'hostname' => 'alpha.example.test', 'driver' => 'simulation']);
    }

    public function test_provisioning_is_idempotent_and_job_cannot_be_read_across_companies(): void
    {
        Queue::fake();
        [$a, $user] = $this->company('alpha');
        [$b] = $this->company('bravo');
        $payload = ['idempotency_key' => (string) Str::uuid()];
        $response = $this->actingAs($user)->postJson('/saas/api/v1/tenants/'.$a->id.'/environments', $payload)->assertAccepted();
        $this->postJson('/saas/api/v1/tenants/'.$a->id.'/environments', $payload)->assertAccepted()->assertJsonPath('id', $response->json('id'));
        $this->postJson('/saas/api/v1/tenants/'.$a->id.'/environments', ['idempotency_key' => Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('environments', 1);
        $this->assertDatabaseCount('operations', 1);
        $this->actingAs($b->members()->first())->getJson('/saas/api/v1/tenants/'.$b->id.'/operations/'.$response->json('id'))->assertNotFound();
        Queue::assertPushed(ProvisionEnvironment::class, 1);
    }

    public function test_simulation_completes_without_claiming_a_real_server(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->company('alpha');
        $id = $this->actingAs($user)->postJson('/saas/api/v1/tenants/'.$tenant->id.'/environments', ['idempotency_key' => Str::uuid()])->assertAccepted()->json('id');
        (new ProvisionEnvironment($id))->handle();
        $this->assertDatabaseHas('environments', ['tenant_id' => $tenant->id, 'status' => 'simulated', 'server_id' => null]);
        $this->assertDatabaseHas('operations', ['id' => $id, 'status' => 'completed']);
        (new ProvisionEnvironment($id))->handle();
        $this->assertDatabaseCount('operations', 1);
    }

    public function test_live_provisioning_is_disabled_by_default(): void
    {
        config(['saas.driver' => 'hetzner', 'saas.provisioning_enabled' => false]);
        [$tenant, $user] = $this->company('alpha');
        $this->actingAs($user)->postJson('/saas/api/v1/tenants/'.$tenant->id.'/environments', ['idempotency_key' => Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('environments', 0);
    }

    public function test_live_provisioning_requires_confirmed_subscription(): void
    {
        config(['saas.driver' => 'hetzner', 'saas.provisioning_enabled' => true]);
        [$tenant, $user] = $this->company('unpaid');
        $this->actingAs($user)->postJson('/saas/api/v1/tenants/'.$tenant->id.'/environments', ['idempotency_key' => Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('environments', 0);
    }

    public function test_revoked_user_cannot_start_a_queued_provisioning_job(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->company('revoked-job');
        $id = $this->actingAs($user)->postJson('/saas/api/v1/tenants/'.$tenant->id.'/environments', ['idempotency_key' => Str::uuid()])->assertAccepted()->json('id');
        $tenant->members()->detach($user->id);
        (new ProvisionEnvironment($id))->handle();
        $this->assertDatabaseHas('operations', ['id' => $id, 'status' => 'failed']);
        $this->assertDatabaseHas('environments', ['tenant_id' => $tenant->id, 'server_id' => null]);
    }

    public function test_mysql_uses_innodb_utf8mb4_and_tenant_indexes(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-specific checks run in the MySQL CI service.');
        }
        $tables = DB::select('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        foreach ($tables as $table) {
            $this->assertSame('InnoDB', $table->ENGINE);
            $this->assertStringStartsWith('utf8mb4_', $table->TABLE_COLLATION);
        }
        foreach (['memberships', 'sites', 'environments', 'operations', 'audit_events'] as $table) {
            $indexes = DB::select('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1', [$table, 'tenant_id']);
            $this->assertNotEmpty($indexes, $table.' needs an index starting with tenant_id');
        }
    }
}
