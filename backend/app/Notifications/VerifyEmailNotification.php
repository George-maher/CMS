<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Carries an email verification link.
 *
 * Two properties make this notification safe to queue:
 *
 *  1. `ShouldBeEncrypted` — Laravel 12 sets `SendQueuedNotifications::$shouldBeEncrypted`
 *     from this marker (see `SendQueuedNotifications::createNotification` and
 *     `Queue::jobShouldBeEncrypted`), and `Queue::createPayload()` then encrypts
 *     the whole serialized job. The `jobs.payload` column therefore never holds
 *     a readable verification token.
 *  2. `failed()` writes metadata only. It must never interpolate `$verificationUrl`
 *     or any exception message that could contain it.
 *
 * @see \App\Services\EmailVerificationService which builds the URL and stores only its hash.
 */
class VerifyEmailNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * Bounded retries with backoff. A permanently failing transport must give
     * up rather than retry a secret-bearing payload forever.
     */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        private readonly User $user,
        private readonly string $verificationUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('auth.verification_email_subject'))
            ->greeting(__('auth.verification_email_greeting', ['name' => $this->user->name ?? '']))
            ->line(__('auth.verification_email_line_1'))
            ->action(__('auth.verification_email_action'), $this->verificationUrl)
            ->line(__('auth.verification_email_line_2'))
            ->line(__('auth.verification_email_line_3'))
            ->salutation(__('auth.verification_email_salutation'));
    }

    /**
     * Terminal failure hook.
     *
     * Records enough to alert on (notification class, account, exception class)
     * and nothing that could replay the credential. The account's stored token
     * hash is intentionally left intact: the user can still complete
     * verification if they retained the original link, and a resend issues a
     * fresh token regardless.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('email_verification', [
            'event' => 'verification_dispatch_failed',
            'notification' => static::class,
            'user_id' => $this->user->getKey(),
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
