<?php

namespace App\Services\Provisioning;

use App\Models\Environment;
use RuntimeException;

class SimulationProvisioner implements Provisioner
{
    public static function enabled(): bool
    {
        return ! app()->environment('production');
    }

    public function install(Environment $environment, callable $progress): void
    {
        if (! self::enabled()) {
            throw new RuntimeException('SIMULATION_DISABLED');
        }
        foreach (['preflight', 'server_simulated', 'dns_simulated', 'installation_simulated'] as $stage) {
            $progress($stage);
        }
        $environment->update(['status' => 'simulated']);
    }
}
