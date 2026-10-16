<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attempt' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
