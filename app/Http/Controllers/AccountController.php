<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function team(Request $request, Tenant $tenant): JsonResponse
    {
        Gate::authorize('view', $tenant);

        return response()->json(['members' => $tenant->members()->get(['users.id', 'name', 'email']), 'can_manage' => $this->isOwner($request, $tenant)]);
    }

    public function company(Request $request, Tenant $tenant): JsonResponse
    {
        Gate::authorize('manage', $tenant);
        $data = $request->validate(['name' => 'required|string|max:150', 'contact_name' => 'nullable|string|max:120', 'phone' => 'nullable|string|max:40', 'tax_code' => 'nullable|string|max:40', 'address' => 'nullable|string|max:500', 'status' => 'prohibited', 'slug' => 'prohibited']);
        DB::transaction(function () use ($request, $tenant, $data) {
            $tenant->update($data);
            AuditEvent::create(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'action' => 'company.profile_updated', 'subject' => (string) $tenant->id]);
        });

        return response()->json($tenant);
    }

    public function member(Request $request, Tenant $tenant): JsonResponse
    {
        abort_unless($this->isOwner($request, $tenant) && $tenant->status === 'active', 403);
        $data = $request->validate(['email' => 'required|email|exists:users,email', 'role' => ['required', Rule::in(['admin', 'operator', 'viewer'])], 'current_password' => 'required|current_password', 'is_platform_admin' => 'prohibited', 'tenant_id' => 'prohibited']);
        $target = User::where('email', $data['email'])->where('is_active', true)->firstOrFail();
        DB::transaction(function () use ($request, $tenant, $target, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_if($tenant->members()->where('users.id', $target->id)->wherePivot('role', 'owner')->exists(), 409, 'Không thay vai trò chủ công ty từ chức năng này.');
            $tenant->members()->syncWithoutDetaching([$target->id => ['role' => $data['role']]]);
            AuditEvent::create(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'action' => 'member.role_assigned', 'subject' => (string) $target->id]);
        });

        return response()->json(['id' => $target->id, 'role' => $data['role']]);
    }

    public function removeMember(Request $request, Tenant $tenant, string $member): JsonResponse
    {
        abort_unless($this->isOwner($request, $tenant), 403);
        $request->validate(['current_password' => 'required|current_password']);
        DB::transaction(function () use ($request, $tenant, $member) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $target = $tenant->members()->where('users.id', $member)->firstOrFail();
            abort_if($target->pivot->role === 'owner', 409, 'Không xóa chủ công ty.');
            $tenant->members()->detach($target->id);
            AuditEvent::create(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'action' => 'member.removed', 'subject' => (string) $target->id]);
        });

        return response()->json(['removed' => true]);
    }

    public function profile(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'prohibited', 'is_platform_admin' => 'prohibited', 'is_active' => 'prohibited']);
        $request->user()->update($data);

        return response()->json(['name' => $request->user()->name]);
    }

    private function isOwner(Request $request, Tenant $tenant): bool
    {
        return $tenant->members()->where('users.id', $request->user()->id)->wherePivot('role', 'owner')->exists();
    }
}
