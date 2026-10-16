<?php

namespace App\Services\Provisioning;

use App\Models\Environment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HetznerProvisioner implements Provisioner
{
    public function install(Environment $environment, callable $progress): void
    {
        $this->preflight($environment);
        $progress('creating_server');
        // Fixed provider origins: never accept URLs, SSH args or shell commands from the customer.
        $http = Http::baseUrl('https://api.hetzner.cloud/v1')->withToken(config('saas.hcloud.token'))->timeout(30)->connectTimeout(10);
        if (! $environment->server_id) {
            $name = 'nac20-'.$environment->tenant_id.'-'.$environment->id;
            $existing = $http->get('/servers', ['name' => $name])->throw()->json('servers');
            if (count($existing) !== 0) {
                throw new RuntimeException('RECONCILE_SERVER_BEFORE_RETRY');
            }
            // No automatic POST retries: a timed-out response can still have created a billable server.
            $response = $http->post('/servers', [
                'name' => $name,
                'server_type' => config('saas.hcloud.server_type'),
                'location' => config('saas.hcloud.location'),
                'image' => 'debian-12',
                'ssh_keys' => [(int) config('saas.hcloud.ssh_key_id')],
                'firewalls' => [['firewall' => (int) config('saas.hcloud.firewall_id')]],
                'labels' => ['managed-by' => 'nac20-saas', 'tenant' => (string) $environment->tenant_id],
                'user_data' => file_get_contents(base_path('infrastructure/cloud-init/nac20.yaml')),
                'public_net' => ['enable_ipv4' => true, 'enable_ipv6' => false],
            ])->throw();
            $server = $response->json('server');
            $environment->update(['server_id' => (string) $server['id']]);
        }
        $server = $http->get('/servers/'.$environment->server_id)->throw()->json('server');
        $ip = $server['public_net']['ipv4']['ip'] ?? '';
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('INVALID_SERVER_ADDRESS');
        }
        $environment->update(['ip_address' => $ip]);
        $progress('configuring_dns');
        app(DnsManager::class)->configure($environment);
        $progress('installing_packages');
        app(AnsibleInstaller::class)->install($environment);
        // The released installer requires its initial configurator: never call this production-ready.
        $environment->update(['status' => 'awaiting_configuration']);
        $progress('awaiting_configuration');
    }

    private function preflight(Environment $environment): void
    {
        foreach (['hcloud.token', 'hcloud.ssh_key_id', 'hcloud.firewall_id', 'hcloud.server_type', 'hcloud.location', 'cloudflare.token', 'cloudflare.zone_id', 'ssh_key', 'known_hosts', 'artifact_url', 'artifact_sha256'] as $key) {
            if (! config('saas.'.$key)) {
                throw new RuntimeException('MISSING_CONFIGURATION');
            }
        }
        if (! preg_match('/\Ahttps:\/\/github\.com\/lehuunghi\/NAC\/releases\/download\/[A-Za-z0-9._-]+\/nac20-debian12-amd64-online\.tar\.gz\z/', $environment->release_url ?? config('saas.artifact_url'))
            || ! preg_match('/\A[a-f0-9]{64}\z/', $environment->release_sha256 ?? config('saas.artifact_sha256'))
            || ! is_file(config('saas.ssh_key')) || ! is_file(config('saas.known_hosts'))
            || ! is_file(config('saas.ansible_binary'))) {
            throw new RuntimeException('INVALID_CONFIGURATION');
        }
    }
}
