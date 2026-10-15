<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $tenant->members()->where('users.id', $user->id)->exists();
    }

    public function manage(User $user, Tenant $tenant): bool
    {
        return $tenant->status === 'active' && $tenant->members()
            ->where('users.id', $user->id)->wherePivotIn('role', ['owner', 'admin'])->exists();
    }
}
