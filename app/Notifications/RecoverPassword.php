<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecoverPassword extends Notification
{
    public function __construct(#[\SensitiveParameter] public string $token) {}

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = rtrim(config('app.url'), '/').'/khoi-phuc-mat-khau#'.http_build_query([
            'token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)->subject('NAC20 — Khôi phục mật khẩu')->greeting('Xin chào,')
            ->line('NAC20 nhận được yêu cầu đặt lại mật khẩu cho tài khoản của bạn.')
            ->action('Đặt mật khẩu mới', $url)->line("Liên kết có hiệu lực trong {$minutes} phút và chỉ dùng được một lần.")
            ->line('Nếu bạn không yêu cầu, hãy bỏ qua thư này. Mật khẩu hiện tại vẫn giữ nguyên.')
            ->salutation('Công Ty TNHH TN20');
    }
}
