<?php

namespace App\Notifications;

use App\Models\ChurchApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewChurchApplicationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ChurchApplication $application,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Church Application: ' . $this->application->church_name)
            ->greeting('New Church Application Received')
            ->line('A new church has applied to join the platform:')
            ->line('')
            ->line('**Church Name:** ' . $this->application->church_name)
            ->line('**Priest Name:** ' . $this->application->priest_name)
            ->line('**Main Servant:** ' . ($this->application->main_servant_name ?? 'N/A'))
            ->line('**Phone:** ' . ($this->application->phone ?? $this->application->priest_phone))
            ->line('**Email:** ' . $this->application->contact_email)
            ->line('**Address:** ' . ($this->application->address ?? 'N/A'))
            ->line('')
            ->action('Review Application', config('app.frontend_url') . '/platform/applications/' . $this->application->id)
            ->line('Please review this application at your earliest convenience.')
            ->salutation('Best regards, The Church Management System');
    }
}
