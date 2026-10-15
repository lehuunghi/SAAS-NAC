<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_vnd' => 'integer', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'paid_at' => 'datetime'];
    }
}
