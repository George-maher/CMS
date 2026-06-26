<?php

namespace App\Notifications;

use App\Models\ChurchApplication;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ChurchApplication $application,
        private readonly User $applicant,
        private readonly string $churchName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Church Registration Has Been Approved - ' . $this->churchName)
            ->greeting('Congratulations, ' . $this->applicant->name . '!')
            ->line('Your church registration for **' . $this->churchName . '** has been approved.')
            ->line('')
            ->line('You can now log in to your church\'s admin dashboard to start managing your community.')
            ->line('')
            ->line('**What you can do now:**')
            ->line('- Manage servants and members')
            ->line('- Set up attendance tracking with QR codes')
            ->line('- Create events and daily verses')
            ->line('- View analytics and reports')
            ->line('')
            ->action('Go to Dashboard', config('app.frontend_url') . '/login')
            ->line('Thank you for choosing our platform to serve your church community.')
            ->salutation('Best regards, The Church Management Team');
    }
}
