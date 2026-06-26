<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SystemMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $subjectText,
        public string $viewName,
        public array $viewData = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectText,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: $this->viewName,
            with: array_merge($this->viewData, [
                'user' => $this->user,
                'locale' => $this->user->preferredLocale(),
                'subjectText' => $this->subjectText,
            ]),
        );
    }
}
