<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RecoverPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.password_recovery_enabled' => true, 'app.url' => 'https://portal.20.com.vn', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        Notification::fake();
    }

    public function test_recovery_requires_opt_in_smtp_and_trusted_https_origin(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach ([
            ['auth.password_recovery_enabled' => false],
            ['mail.default' => 'log'],
            ['mail.default' => 'array'],
            ['mail.default' => 'failover'],
            ['app.url' => 'http://portal.20.com.vn'],
            ['app.url' => 'https://user@portal.20.com.vn'],
            ['app.url' => 'https://portal.20.com.vn?redirect=evil'],
            ['app.url' => 'https://portal.20.com.vn/path'],
        ] as $settings) {
            $original = collect(array_keys($settings))->mapWithKeys(fn (string $key) => [$key => config($key)])->all();
            config($settings);
            $this->getJson('/saas/api/v1/auth-options')->assertOk()->assertJsonPath('password_recovery_enabled', false);
            $this->postJson('/saas/api/v1/forgot-password', ['email' => 'owner@example.test'])->assertServiceUnavailable();
            $this->postJson('/saas/api/v1/reset-password')->assertServiceUnavailable();
            config($original);
        }
        Notification::assertNothingSent();
    }

    public function test_forgot_password_has_same_response_for_active_unknown_locked_and_throttled_accounts(): void
    {
        $active = User::factory()->create(['email' => 'owner@example.test']);
        $locked = User::factory()->create(['is_active' => false]);
        $expected = $this->postJson('/saas/api/v1/forgot-password', ['email' => 'OWNER@EXAMPLE.TEST'])->assertOk()->assertJsonMissingPath('token')->json();
        $this->postJson('/saas/api/v1/forgot-password', ['email' => 'unknown@example.test'])->assertOk()->assertExactJson($expected);
        $this->postJson('/saas/api/v1/forgot-password', ['email' => $locked->email])->assertOk()->assertExactJson($expected);
        $this->postJson('/saas/api/v1/forgot-password', ['email' => $active->email])->assertOk()->assertExactJson($expected);
        Notification::assertSentToTimes($active, RecoverPassword::class, 1);
        Notification::assertNotSentTo($locked, RecoverPassword::class);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        Notification::assertSentTo($active, RecoverPassword::class, function (RecoverPassword $notification) use ($active): bool {
            $this->assertNotSame($notification->token, DB::table('password_reset_tokens')->value('token'));

            return Password::tokenExists($active, $notification->token);
        });
    }

    public function test_mail_link_uses_configured_origin_and_fragment_instead_of_request_host_or_query(): void
    {
        $user = User::factory()->create();
        $this->withHeader('Host', 'attacker.example')->postJson('/saas/api/v1/forgot-password', ['email' => $user->email])->assertOk();
        Notification::assertSentTo($user, RecoverPassword::class, function (RecoverPassword $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $this->assertStringStartsWith('https://portal.20.com.vn/khoi-phuc-mat-khau#', $mail->actionUrl);
            $this->assertStringNotContainsString('attacker.example', $mail->actionUrl);
            $this->assertNull(parse_url($mail->actionUrl, PHP_URL_QUERY));
            parse_str(parse_url($mail->actionUrl, PHP_URL_FRAGMENT), $fragment);
            $this->assertSame($notification->token, $fragment['token']);
            $this->assertSame($user->email, $fragment['email']);
            $this->assertStringContainsString('Khôi phục mật khẩu', $mail->subject);

            return true;
        });
        $this->withoutVite();
        $this->get('/khoi-phuc-mat-khau')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_reset_is_single_use_revokes_old_sessions_and_clears_initial_password_requirement(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true, 'must_change_password' => true, 'password' => 'Original-Password-2026!']);
        $oldHash = $user->password;
        $oldRemember = $user->remember_token;
        $token = Password::createToken($user);
        $body = $this->resetBody($user, $token);
        $this->postJson('/saas/api/v1/reset-password', $body)->assertOk()->assertJsonStructure(['message', 'csrf_token'])->assertJsonMissingPath('token');
        $this->assertGuest();
        $user->refresh();
        $this->assertTrue(Hash::check($body['password'], $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertTrue($user->is_platform_admin);
        $this->assertTrue($user->is_active);
        $this->assertNotSame($oldRemember, $user->remember_token);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseHas('admin_audit_events', ['user_id' => $user->id, 'action' => 'account.password_reset']);
        $details = DB::table('admin_audit_events')->where('user_id', $user->id)->where('action', 'account.password_reset')->value('details');
        $this->assertSame([], json_decode($details, true, 512, JSON_THROW_ON_ERROR));
        $this->postJson('/saas/api/v1/reset-password', $body)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->actingAs($user)->withSession(['password_hash_web' => Auth::guard('web')->hashPasswordForCookie($oldHash)])
            ->getJson('/saas/api/v1/me')->assertUnauthorized();
        $this->postJson('/saas/api/v1/login', ['email' => $user->email, 'password' => $body['password']])->assertOk();
    }

    public function test_wrong_email_invalid_expired_and_locked_tokens_cannot_change_password(): void
    {
        $user = User::factory()->create(['password' => 'Original-Password-2026!']);
        $other = User::factory()->create();
        $token = Password::createToken($user);
        $expected = ['token' => ['Liên kết không hợp lệ hoặc đã hết hạn. Vui lòng yêu cầu liên kết mới.']];
        $this->postJson('/saas/api/v1/reset-password', $this->resetBody($other, $token))->assertUnprocessable()->assertJsonPath('errors', $expected);
        $this->postJson('/saas/api/v1/reset-password', $this->resetBody($user, 'invalid'))->assertUnprocessable()->assertJsonPath('errors', $expected);
        $this->travel(61)->minutes();
        $this->postJson('/saas/api/v1/reset-password', $this->resetBody($user, $token))->assertUnprocessable()->assertJsonPath('errors', $expected);
        $this->travelBack();
        $user->forceFill(['is_active' => false])->save();
        $this->postJson('/saas/api/v1/reset-password', $this->resetBody($user, $token))->assertUnprocessable()->assertJsonPath('errors', $expected);
        $this->assertTrue(Hash::check('Original-Password-2026!', $user->fresh()->password));
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_reset_rejects_weak_unconfirmed_passwords_and_account_injection(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $body = $this->resetBody($user, $token);
        $this->postJson('/saas/api/v1/reset-password')->assertUnprocessable()->assertJsonValidationErrors(['email', 'token', 'password']);
        $this->postJson('/saas/api/v1/reset-password', [...$body, 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/saas/api/v1/reset-password', [...$body, 'password_confirmation' => 'mismatched'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/saas/api/v1/reset-password', [...$body, 'user_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertTrue(Password::tokenExists($user, $token));
    }

    public function test_recovery_rate_limits_email_across_ips_and_reset_attempts_by_ip(): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($attempt + 1)])->postJson('/saas/api/v1/forgot-password', ['email' => 'owner@example.test'])->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->postJson('/saas/api/v1/forgot-password', ['email' => 'OWNER@EXAMPLE.TEST'])->assertTooManyRequests();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/saas/api/v1/reset-password')->assertUnprocessable();
        }
        $this->postJson('/saas/api/v1/reset-password')->assertTooManyRequests();
    }

    public function test_smtp_failure_returns_generic_message_without_logging_token_or_exception(): void
    {
        $user = User::factory()->create();
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('sensitive-mail-body-and-token'));
        Log::spy();
        $this->postJson('/saas/api/v1/forgot-password', ['email' => $user->email])->assertOk()
            ->assertJsonPath('message', 'Nếu email thuộc tài khoản đang hoạt động, bạn sẽ nhận được liên kết khôi phục. Vui lòng kiểm tra cả thư rác.');
        Log::shouldHaveReceived('warning')->once()->with('Không thể gửi thư khôi phục NAC20; kiểm tra kết nối SMTP.');
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_invalid_email_payload_is_validated_and_recovery_ip_limit_applies_across_emails(): void
    {
        $this->postJson('/saas/api/v1/forgot-password', ['email' => ['invalid']])->assertUnprocessable()->assertJsonValidationErrors('email');
        for ($attempt = 0; $attempt < 9; $attempt++) {
            $this->postJson('/saas/api/v1/forgot-password', ['email' => "unknown{$attempt}@example.test"])->assertOk();
        }
        $this->postJson('/saas/api/v1/forgot-password', ['email' => 'another@example.test'])->assertTooManyRequests();
    }

    public function test_console_created_owner_must_change_password_before_using_portal(): void
    {
        $this->artisan('saas:company', ['slug' => 'console-company', 'name' => 'Công ty console', 'email' => 'console@example.test'])
            ->expectsQuestion('Mật khẩu chủ công ty (tối thiểu 12 ký tự)', 'Initial-Password-2026!')->assertExitCode(0);
        $owner = User::where('email', 'console@example.test')->firstOrFail();
        $this->assertTrue($owner->must_change_password);
        $this->actingAs($owner)->getJson('/saas/api/v1/tenants')->assertStatus(423);
    }

    public function test_admin_issued_password_blocks_business_endpoints_until_changed(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true, 'password' => 'Admin-Password-2026!']);
        $this->actingAs($admin)->postJson('/saas/api/v1/admin/customers', [
            'name' => 'Công ty khách', 'slug' => 'customer-new', 'create_owner' => true,
            'owner_name' => 'Chủ công ty', 'owner_email' => 'new-owner@example.test',
            'owner_password' => 'Initial-Password-2026!', 'owner_password_confirmation' => 'Initial-Password-2026!',
            'current_password' => 'Admin-Password-2026!',
        ])->assertCreated();
        $owner = User::where('email', 'new-owner@example.test')->firstOrFail();
        $this->assertTrue($owner->must_change_password);
        $this->flushSession();
        $this->actingAs($owner)->getJson('/saas/api/v1/me')->assertOk()->assertJsonPath('must_change_password', true);
        $this->getJson('/saas/api/v1/tenants')->assertStatus(423)->assertJsonPath('code', 'password_change_required');
        $this->postJson('/saas/api/v1/profile', ['name' => 'Bypass', 'must_change_password' => false])->assertStatus(423);
        $this->getJson('/saas/api/v1/admin/overview')->assertStatus(423);
        $this->postJson('/saas/api/v1/password', [
            'current_password' => 'Initial-Password-2026!', 'password' => 'Private-Password-2026!', 'password_confirmation' => 'Private-Password-2026!',
        ])->assertOk();
        $this->getJson('/saas/api/v1/tenants')->assertOk()->assertJsonCount(1);
        $this->assertFalse($owner->fresh()->must_change_password);
    }

    public function test_initial_password_gate_uses_fresh_state_and_allows_logout(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/saas/api/v1/tenants')->assertOk();
        User::whereKey($user->id)->update(['must_change_password' => true]);
        $this->getJson('/saas/api/v1/tenants')->assertStatus(423);
        $this->postJson('/saas/api/v1/logout')->assertNoContent();
        $this->assertGuest();
    }

    /** @return array{email: string, token: string, password: string, password_confirmation: string} */
    private function resetBody(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'Replacement-Password-2026!', 'password_confirmation' => 'Replacement-Password-2026!'];
    }
}
