<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetRequestApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $resetUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferredLocale();

        if ($locale === 'ar') {
            return (new MailMessage)
                ->subject('تمت الموافقة على طلب إعادة تعيين كلمة المرور')
                ->greeting('مرحباً ' . ($notifiable->name ?? '') . '!')
                ->line('تمت الموافقة على طلب إعادة تعيين كلمة المرور.')
                ->line('يمكنك الآن تعيين كلمة مرور جديدة باستخدام الرابط أدناه.')
                ->action('تعيين كلمة مرور جديدة', $this->resetUrl)
                ->line('سينتهي صلاحية هذا الرابط خلال 24 ساعة.')
                ->line('إذا لم تطلب إعادة تعيين كلمة المرور، يرجى التواصل مع الدعم الفني فوراً.')
                ->salutation('مع تحيات فريق الكنيسة');
        }

        return (new MailMessage)
            ->subject('Your Password Reset Request Has Been Approved')
            ->greeting('Hello ' . ($notifiable->name ?? '') . '!')
            ->line('Your password reset request has been approved.')
            ->line('You may now set a new password using the link below.')
            ->action('Set New Password', $this->resetUrl)
            ->line('This link will expire in 24 hours.')
            ->line('If this wasn\'t you, please contact support immediately.')
            ->salutation('Best regards, The Church Team');
    }
}
