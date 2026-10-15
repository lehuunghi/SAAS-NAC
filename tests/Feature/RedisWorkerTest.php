<?php

namespace Tests\Feature;

use App\Jobs\ProvisionEnvironment;
use App\Models\Environment;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RedisWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_redis_worker_processes_simulated_provisioning(): void
    {
        if (! env('SAAS_REDIS_TESTS')) {
            $this->markTestSkipped('Redis worker integration is enabled in CI.');
        }
        config(['cache.default' => 'redis']);
        $tenant = Tenant::create(['name' => 'Redis integration', 'slug' => 'redis-test']);
        $user = User::factory()->create();
        $tenant->members()->attach($user->id, ['role' => 'owner']);
        $environment = Environment::create(['tenant_id' => $tenant->id, 'hostname' => 'redis.example.test', 'driver' => 'simulation']);
        $operation = Operation::create(['tenant_id' => $tenant->id, 'environment_id' => $environment->id, 'requested_by' => $user->id, 'idempotency_key' => (string) Str::uuid()]);
        $queue = 'test-'.Str::uuid();
        Queue::connection('redis')->push((new ProvisionEnvironment($operation->id))->onQueue($queue), '', $queue);
        $this->assertSame(1, Queue::connection('redis')->size($queue));
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 1]);
        $this->assertSame(0, Queue::connection('redis')->size($queue));
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertSame('simulated', $environment->fresh()->status);
    }
}
