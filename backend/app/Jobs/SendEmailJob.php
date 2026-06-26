<?php

namespace App\Jobs;

use App\Mail\SystemMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public ?int $retryUntil = null;

    public function __construct(
        private readonly User $user,
        private readonly string $subjectText,
        private readonly string $viewName,
        private readonly array $viewData = [],
    ) {
        $this->retryUntil = now()->addMinutes(30)->getTimestamp();
    }

    public function handle(): void
    {
        if (!$this->user->email) {
            Log::warning('Cannot send email: user has no email address', [
                'user_id' => $this->user->id,
            ]);
            return;
        }

        try {
            Mail::send(new SystemMail(
                user: $this->user,
                subjectText: $this->subjectText,
                viewName: $this->viewName,
                viewData: $this->viewData,
            ));

            Log::info('Email sent successfully', [
                'user_id' => $this->user->id,
                'email' => $this->user->email,
                'subject' => $this->subjectText,
                'view' => $this->viewName,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send email', [
                'user_id' => $this->user->id,
                'email' => $this->user->email,
                'subject' => $this->subjectText,
                'view' => $this->viewName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Email job permanently failed after all retries', [
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'subject' => $this->subjectText,
            'view' => $this->viewName,
            'error' => $e->getMessage(),
        ]);
    }
}
