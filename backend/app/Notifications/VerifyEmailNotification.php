<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly User $user,
        private readonly string $verificationUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify Your Email Address')
            ->greeting('Hello ' . $this->user->name . '!')
            ->line('Thank you for registering. Please click the button below to verify your email address.')
            ->action('Verify Email Address', $this->verificationUrl)
            ->line('This verification link will expire in 24 hours.')
            ->line('If you did not create an account, no further action is required.')
            ->salutation('Best regards, The Church Team');
    }
}
