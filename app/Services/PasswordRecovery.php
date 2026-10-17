<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\RecoverPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class PasswordRecovery
{
    public function available(): bool
    {
        $url = parse_url((string) config('app.url'));
        $mailer = config('mail.mailers.'.config('mail.default'), []);

        return config('auth.password_recovery_enabled') === true
            && is_array($url) && ($url['scheme'] ?? null) === 'https' && ! empty($url['host'])
            && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment'])
            && in_array($url['path'] ?? '', ['', '/'], true)
            && ($mailer['transport'] ?? null) === 'smtp' && ! empty($mailer['host']);
    }

    public function send(string $email): void
    {
        try {
            DB::transaction(function () use ($email): void {
                User::where('email', $email)->lockForUpdate()->first();
                Password::sendResetLink(['email' => $email, 'is_active' => true], function (User $user, #[\SensitiveParameter] string $token): void {
                    $user->notify(new RecoverPassword($token));
                });
            });
        } catch (Throwable) {
            Log::warning('Không thể gửi thư khôi phục NAC20; kiểm tra kết nối SMTP.');
        }
    }

    /** @param array{email: string, token: string, password: string, password_confirmation: string} $credentials */
    public function reset(#[\SensitiveParameter] array $credentials): string
    {
        return DB::transaction(function () use ($credentials): string {
            User::where('email', $credentials['email'])->lockForUpdate()->first();

            return Password::reset([...$credentials, 'is_active' => true], function (User $user, #[\SensitiveParameter] string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'must_change_password' => false])->save();
                if ($user->is_platform_admin) {
                    DB::table('admin_audit_events')->insert([
                        'user_id' => $user->id, 'tenant_id' => null, 'action' => 'account.password_reset',
                        'subject' => (string) $user->id, 'details' => '{}', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                event(new PasswordReset($user));
            });
        });
    }
}
