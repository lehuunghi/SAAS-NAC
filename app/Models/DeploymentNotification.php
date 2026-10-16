<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentNotification extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
        if (! $user->fresh()?->is_platform_admin) {
            $query->whereHas('tenant.members', fn ($members) => $members->where('users.id', $user->id));
        }
    }
}
