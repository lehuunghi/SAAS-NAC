<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Environment extends Model
{
    protected $fillable = ['tenant_id', 'site_id', 'hostname', 'status', 'driver', 'server_id', 'ip_address', 'release_url', 'release_sha256', 'deployment_notes', 'handed_over_at', 'acceptance_evidence', 'ssh_host', 'ssh_port', 'ssh_user', 'ssh_host_key', 'ssh_password'];

    protected $hidden = ['ssh_password', 'ssh_host_key', 'access_url', 'access_account', 'access_instructions'];

    protected function casts(): array
    {
        return ['ssh_password' => 'encrypted', 'ssh_port' => 'integer', 'handed_over_at' => 'datetime'];
    }
}
