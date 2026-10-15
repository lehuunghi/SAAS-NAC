<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'action', 'subject'];
}
