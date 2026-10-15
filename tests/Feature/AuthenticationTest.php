<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seeding_creates_no_login_accounts(): void
    {
        $this->seed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_logout_and_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'Test-only-Password-123']);
        $this->postJson('/saas/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/saas/api/v1/login', ['email' => $user->email, 'password' => 'Test-only-Password-123'])->assertOk();
        $this->getJson('/saas/api/v1/me')->assertOk()->assertJsonPath('email', $user->email);
        $this->postJson('/saas/api/v1/logout')->assertNoContent();
        $this->getJson('/saas/api/v1/me')->assertUnauthorized();
    }
}
