<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Services\Provisioning\HetznerProvisioner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ProvisionerTest extends TestCase
{
    private array $files = [];

    private function configure(): void
    {
        $this->files = [tempnam(sys_get_temp_dir(), 'key'), tempnam(sys_get_temp_dir(), 'hosts'), tempnam(sys_get_temp_dir(), 'ansible')];
        config([
            'saas.hcloud.token' => 'test-provider-token', 'saas.hcloud.ssh_key_id' => 1,
            'saas.hcloud.firewall_id' => 1,
            'saas.hcloud.server_type' => 'test-type', 'saas.hcloud.location' => 'test-location',
            'saas.cloudflare.token' => 'test-dns-token', 'saas.cloudflare.zone_id' => str_repeat('a', 32),
            'saas.ssh_key' => $this->files[0], 'saas.known_hosts' => $this->files[1], 'saas.ansible_binary' => $this->files[2],
            'saas.artifact_url' => 'https://github.com/lehuunghi/NAC/releases/download/test/nac20-debian12-amd64-online.tar.gz',
            'saas.artifact_sha256' => str_repeat('a', 64),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            unlink($file);
        }
        parent::tearDown();
    }

    public function test_missing_configuration_never_calls_infrastructure(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        try {
            app(HetznerProvisioner::class)->install(new Environment, fn () => null);
            $this->fail('Expected missing configuration failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('MISSING_CONFIGURATION', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_untrusted_artifact_url_is_rejected_before_network_calls(): void
    {
        $this->configure();
        config(['saas.artifact_url' => 'http://169.254.169.254/latest/meta-data']);
        Http::fake();
        try {
            app(HetznerProvisioner::class)->install(new Environment, fn () => null);
            $this->fail('Expected invalid configuration failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('INVALID_CONFIGURATION', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_existing_ambiguous_server_is_not_created_again(): void
    {
        $this->configure();
        Http::preventStrayRequests();
        Http::fake(['https://api.hetzner.cloud/v1/servers*' => Http::response(['servers' => [['id' => 42]]])]);
        try {
            app(HetznerProvisioner::class)->install(new Environment(['tenant_id' => 1]), fn () => null);
            $this->fail('Expected reconciliation failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('RECONCILE_SERVER_BEFORE_RETRY', $e->getMessage());
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_existing_server_installs_without_duplicate_creation_and_keeps_configuration_pending(): void
    {
        $this->configure();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.hetzner.cloud/v1/servers/42' => Http::response(['server' => ['public_net' => ['ipv4' => ['ip' => '8.8.4.4']]]]),
            'https://api.cloudflare.com/*' => Http::sequence()->push(['result' => []])->push(['success' => true]),
        ]);
        Process::fake();
        $environment = \Mockery::mock(Environment::class)->makePartial();
        $environment->fill(['tenant_id' => 1, 'server_id' => '42', 'hostname' => 'alpha.nac.example.test']);
        $environment->shouldReceive('update')->with(['ip_address' => '8.8.4.4'])->once()->andReturnUsing(function ($values) use ($environment) {
            $environment->fill($values);

            return true;
        });
        $environment->shouldReceive('update')->with(['status' => 'awaiting_configuration'])->once()->andReturnTrue();
        app(HetznerProvisioner::class)->install($environment, fn () => null);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'hetzner'));
        Process::assertRan(fn ($process) => is_array($process->command) && $process->command[0] === $this->files[2]);
    }
}
