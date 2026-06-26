<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url');
        $resetUrl = $frontendUrl . '/reset-password?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $locale = $notifiable->preferredLocale();

        if ($locale === 'ar') {
            return (new MailMessage)
                ->subject('إعادة تعيين كلمة المرور')
                ->greeting('مرحباً ' . ($notifiable->name ?? '') . '!')
                ->line('لقد تلقينا طلباً لإعادة تعيين كلمة المرور لحسابك.')
                ->action('إعادة تعيين كلمة المرور', $resetUrl)
                ->line('سينتهي صلاحية رابط إعادة التعيين خلال 60 دقيقة.')
                ->line('إذا لم تطلب إعادة تعيين كلمة المرور، فلا حاجة لاتخاذ أي إجراء.')
                ->salutation('مع تحيات فريق الكنيسة');
        }

        return (new MailMessage)
            ->subject('Reset Your Password')
            ->greeting('Hello ' . ($notifiable->name ?? '') . '!')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $resetUrl)
            ->line('This password reset link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.')
            ->salutation('Best regards, The Church Team');
    }
}
