<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Environment extends Model
{
    protected $fillable = ['tenant_id', 'site_id', 'hostname', 'status', 'driver', 'server_id', 'ip_address'];
}
