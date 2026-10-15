<?php

namespace App\Services\Provisioning;

use App\Models\Environment;

class SimulationProvisioner implements Provisioner
{
    public function install(Environment $environment, callable $progress): void
    {
        foreach (['preflight', 'server_simulated', 'dns_simulated', 'installation_simulated'] as $stage) {
            $progress($stage);
        }
        $environment->update(['status' => 'simulated']);
    }
}
