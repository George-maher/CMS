<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct() {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferredLocale();

        if ($locale === 'ar') {
            return (new MailMessage)
                ->subject('تم تغيير كلمة المرور بنجاح')
                ->greeting('مرحباً ' . ($notifiable->name ?? '') . '!')
                ->line('تم تغيير كلمة المرور الخاصة بك بنجاح.')
                ->line('إذا لم تكن أنت من قام بهذا التغيير، يرجى التواصل مع الدعم الفني فوراً.')
                ->salutation('مع تحيات فريق الكنيسة');
        }

        return (new MailMessage)
            ->subject('Your Password Has Been Changed')
            ->greeting('Hello ' . ($notifiable->name ?? '') . '!')
            ->line('Your password has been changed successfully.')
            ->line('If this wasn\'t you, please contact support immediately.')
            ->salutation('Best regards, The Church Team');
    }
}
