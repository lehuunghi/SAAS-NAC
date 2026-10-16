<?php

return [
    'domain' => env('NAC_DOMAIN', 'nac.20.com.vn'),
    'driver' => env('PROVISIONING_DRIVER', 'simulation'),
    'provisioning_enabled' => env('PROVISIONING_ENABLED', false),
    'ssh_enabled' => env('SSH_INSTALL_ENABLED', false),
    'artifact_url' => env('NAC_ARTIFACT_URL'),
    'artifact_sha256' => env('NAC_ARTIFACT_SHA256'),
    'ansible_binary' => env('ANSIBLE_BINARY', '/usr/bin/ansible-playbook'),
    'ssh_key' => env('PROVISIONING_SSH_KEY'),
    'known_hosts' => env('PROVISIONING_KNOWN_HOSTS'),
    'hcloud' => [
        'token' => env('HCLOUD_TOKEN'),
        'server_type' => env('HCLOUD_SERVER_TYPE'),
        'location' => env('HCLOUD_LOCATION'),
        'ssh_key_id' => env('HCLOUD_SSH_KEY_ID'),
        'firewall_id' => env('HCLOUD_FIREWALL_ID'),
    ],
    'cloudflare' => ['token' => env('CLOUDFLARE_API_TOKEN'), 'zone_id' => env('CLOUDFLARE_ZONE_ID')],
];
