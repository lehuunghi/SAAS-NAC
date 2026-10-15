<?php

namespace App\Services\Provisioning;

use App\Models\Environment;

interface Provisioner
{
    public function install(Environment $environment, callable $progress): void;
}
