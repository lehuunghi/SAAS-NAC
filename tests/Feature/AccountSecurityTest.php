<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_changes_only_current_account_and_preserves_current_session(): void
    {
        $user = User::factory()->create(['password' => 'Original-Password-2026!', 'is_platform_admin' => true]);
        $other = User::factory()->create(['password' => 'Other-Password-2026!']);
        $oldHash = $user->password;
        $this->actingAs($user)->getJson('/saas/api/v1/me')->assertOk();
        $this->postJson('/saas/api/v1/password', [
            'current_password' => 'Original-Password-2026!', 'password' => 'Replacement-Password-2026!', 'password_confirmation' => 'Replacement-Password-2026!',
        ])->assertOk()->assertJsonStructure(['message', 'csrf_token'])->assertJsonMissingPath('password');
        $this->assertTrue(Hash::check('Replacement-Password-2026!', $user->fresh()->password));
        $this->assertTrue(Hash::check('Other-Password-2026!', $other->fresh()->password));
        $this->getJson('/saas/api/v1/admin/overview')->assertOk();
        $this->assertDatabaseHas('admin_audit_events', ['user_id' => $user->id, 'action' => 'account.password_changed', 'details' => '{}']);

        // Simulate a second browser that still holds the previous credential hash.
        $this->withSession(['password_hash_web' => Auth::guard('web')->hashPasswordForCookie($oldHash)])
            ->getJson('/saas/api/v1/me')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_password_requires_current_password_confirmation_and_minimum_length(): void
    {
        $user = User::factory()->create(['password' => 'Original-Password-2026!']);
        $this->actingAs($user);
        foreach ([
            ['wrong', 'Replacement-Password-2026!', 'Replacement-Password-2026!', 'current_password'],
            ['Original-Password-2026!', 'short', 'short', 'password'],
            ['Original-Password-2026!', 'Replacement-Password-2026!', 'mismatched', 'password'],
            ['Original-Password-2026!', 'Original-Password-2026!', 'Original-Password-2026!', 'password'],
        ] as [$current, $password, $confirmation, $field]) {
            $this->postJson('/saas/api/v1/password', ['current_password' => $current, 'password' => $password, 'password_confirmation' => $confirmation])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertTrue(Hash::check('Original-Password-2026!', $user->fresh()->password));
    }

    public function test_password_endpoint_rejects_guests_and_target_account_injection(): void
    {
        $this->postJson('/saas/api/v1/password')->assertUnauthorized();
        $user = User::factory()->create(['password' => 'Original-Password-2026!']);
        $this->actingAs($user)->postJson('/saas/api/v1/password', [
            'current_password' => 'Original-Password-2026!', 'password' => 'Replacement-Password-2026!', 'password_confirmation' => 'Replacement-Password-2026!', 'user_id' => 99,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_login_accepts_email_case_and_initializes_session_credential_check(): void
    {
        User::factory()->create(['email' => 'owner@example.test', 'password' => 'Original-Password-2026!']);
        $this->postJson('/saas/api/v1/login', ['email' => 'OWNER@EXAMPLE.TEST', 'password' => 'Original-Password-2026!'])->assertOk()->assertSessionHas('password_hash_web');
        $this->getJson('/saas/api/v1/me')->assertOk();
    }

    public function test_password_attempts_are_rate_limited(): void
    {
        $this->actingAs(User::factory()->create());
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/saas/api/v1/password')->assertUnprocessable();
        }
        $this->postJson('/saas/api/v1/password')->assertTooManyRequests();
    }
}
