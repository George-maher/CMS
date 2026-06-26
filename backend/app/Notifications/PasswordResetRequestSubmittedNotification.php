<?php

namespace App\Notifications;

use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetRequestSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly PasswordResetRequest $request,
        private readonly User $requester,
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
                ->subject('طلب إعادة تعيين كلمة مرور جديد')
                ->greeting('مرحباً ' . ($notifiable->name ?? '') . '!')
                ->line('تم تقديم طلب جديد لإعادة تعيين كلمة المرور بواسطة ' . $this->requester->name)
                ->line('البريد الإلكتروني: ' . $this->requester->email)
                ->line('يرجى مراجعة الطلب في لوحة التحكم.')
                ->action('مراجعة الطلب', config('app.frontend_url') . '/admin/password-reset-requests')
                ->salutation('مع تحيات النظام');
        }

        return (new MailMessage)
            ->subject('New Password Reset Request')
            ->greeting('Hello ' . ($notifiable->name ?? '') . '!')
            ->line('A new password reset request has been submitted by ' . $this->requester->name)
            ->line('Email: ' . $this->requester->email)
            ->line('Please review the request in the admin panel.')
            ->action('Review Request', config('app.frontend_url') . '/admin/password-reset-requests')
            ->salutation('Best regards, The System');
    }
}
