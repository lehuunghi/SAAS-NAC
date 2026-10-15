<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceSubscription extends Model
{
    protected $fillable = ['tenant_id', 'status', 'payment_reference', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
