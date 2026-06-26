<?php

namespace App\Notifications;

use App\Models\ChurchApplication;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ChurchApplication $application,
        private readonly User $applicant,
        private readonly string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Church Registration Update - ' . $this->application->church_name)
            ->greeting('Dear ' . $this->applicant->name . ',')
            ->line('Your church registration application for **' . $this->application->church_name . '** has been reviewed.')
            ->line('')
            ->line('Unfortunately, your application could not be approved at this time.')
            ->line('')
            ->line('**Reason for rejection:**')
            ->line($this->reason)
            ->line('')
            ->line('If you have any questions or would like to reapply with corrected information,')
            ->line('please feel free to contact our support team.')
            ->line('')
            ->action('View Application Status', config('app.frontend_url') . '/login')
            ->salutation('Best regards, The Church Management Team');
    }
}
