<?php

namespace App\Services\Provisioning;

use App\Models\Environment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DnsManager
{
    public function configure(Environment $environment): void
    {
        if (! config('saas.cloudflare.token') || ! config('saas.cloudflare.zone_id')) {
            throw new RuntimeException('MISSING_CONFIGURATION');
        }
        if (! preg_match('/\A[a-z0-9-]+(?:\.[a-z0-9-]+)+\z/', $environment->hostname)) {
            throw new RuntimeException('INVALID_HOSTNAME');
        }
        $zone = config('saas.cloudflare.zone_id');
        if (! preg_match('/\A[a-f0-9]{32}\z/', $zone)) {
            throw new RuntimeException('INVALID_ZONE');
        }
        $http = Http::baseUrl('https://api.cloudflare.com/client/v4/zones/'.$zone)
            ->withToken(config('saas.cloudflare.token'))->timeout(30)->connectTimeout(10);
        $records = $http->get('/dns_records', ['name' => $environment->hostname])->throw()->json('result');
        if (count($records) > 0) {
            if (count($records) !== 1 || $records[0]['type'] !== 'A' || $records[0]['content'] !== $environment->ip_address || $records[0]['proxied']) {
                throw new RuntimeException('DNS_CONFLICT');
            }

            return;
        }
        $http->post('/dns_records', ['type' => 'A', 'name' => $environment->hostname, 'content' => $environment->ip_address, 'ttl' => 300, 'proxied' => false])->throw();
    }
}
