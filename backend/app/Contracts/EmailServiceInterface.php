<?php

namespace App\Contracts;

use App\Models\User;

interface EmailServiceInterface
{
    public function sendPasswordReset(User $user, string $token): void;

    public function sendInviteEmail(User $user, string $inviteUrl): void;

    public function sendNotification(User $user, string $subject, string $content): void;

    public function sendFeedbackReply(User $user, string $feedbackMessage, string $replyMessage): void;

    public function sendWelcomeEmail(User $user): void;

    public function sendRegistrationSubmitted(User $user): void;

    public function sendApplicationApproved(User $user, string $churchName): void;

    public function sendApplicationRejected(User $user, string $reason): void;

    public function sendAttendanceNotification(User $user, string $className, string $date): void;

    public function sendEventNotification(User $user, string $eventName, string $eventDate, string $eventUrl): void;
}
