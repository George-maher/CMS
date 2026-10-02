<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Carries a password-reset token (Phase 1C finding A-5).
 *
 * `ShouldBeEncrypted` is the project standard for secret-bearing queued
 * notifications (see VerifyEmailNotification for the mechanism): Laravel sets
 * `SendQueuedNotifications::$shouldBeEncrypted` from this marker and
 * `Queue::createPayload()` encrypts the whole serialized job, so the
 * `jobs.payload` column never holds a readable reset token.
 *
 * NOTE: dispatch happens only via User::sendPasswordResetNotification()
 * (the CanResetPassword contract method), whose sole caller is Laravel's
 * password broker — and no code in this project invokes the broker (recovery
 * goes through the admin-approval flow, AuthService::forgotPassword →
 * PasswordResetRequestService::submitRequest). The interface is therefore
 * defense-in-depth: if the broker path is ever (re)enabled, the queued
 * payload must already be encrypted.
 */
class ResetPasswordNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        /** @var string $frontendUrl */
        $frontendUrl = config('app.frontend_url');
        $resetUrl = $frontendUrl.'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $locale = $notifiable->preferredLocale();

        if ($locale === 'ar') {
            return (new MailMessage)
                ->subject('إعادة تعيين كلمة المرور')
                ->greeting('مرحباً '.($notifiable->name ?? '').'!')
                ->line('لقد تلقينا طلباً لإعادة تعيين كلمة المرور لحسابك.')
                ->action('إعادة تعيين كلمة المرور', $resetUrl)
                ->line('سينتهي صلاحية رابط إعادة التعيين خلال 60 دقيقة.')
                ->line('إذا لم تطلب إعادة تعيين كلمة المرور، فلا حاجة لاتخاذ أي إجراء.')
                ->salutation('مع تحيات فريق الكنيسة');
        }

        return (new MailMessage)
            ->subject('Reset Your Password')
            ->greeting('Hello '.($notifiable->name ?? '').'!')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $resetUrl)
            ->line('This password reset link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.')
            ->salutation('Best regards, The Church Team');
    }
}
