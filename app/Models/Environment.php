<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Environment extends Model
{
    protected $fillable = ['tenant_id', 'site_id', 'hostname', 'status', 'driver', 'server_id', 'ip_address', 'release_url', 'release_sha256', 'deployment_notes', 'handed_over_at', 'acceptance_evidence'];
}
