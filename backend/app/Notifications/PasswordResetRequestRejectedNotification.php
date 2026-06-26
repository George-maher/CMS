<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetRequestRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $reason,
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
                ->subject('تم رفض طلب إعادة تعيين كلمة المرور')
                ->greeting('مرحباً ' . ($notifiable->name ?? '') . '!')
                ->line('تم رفض طلب إعادة تعيين كلمة المرور.')
                ->line('السبب: ' . $this->reason)
                ->line('إذا كان لديك أي استفسار، يرجى التواصل مع المسؤول.')
                ->salutation('مع تحيات فريق الكنيسة');
        }

        return (new MailMessage)
            ->subject('Your Password Reset Request Was Rejected')
            ->greeting('Hello ' . ($notifiable->name ?? '') . '!')
            ->line('Your password reset request was rejected.')
            ->line('Reason: ' . $this->reason)
            ->line('If you have any questions, please contact an administrator.')
            ->salutation('Best regards, The Church Team');
    }
}
