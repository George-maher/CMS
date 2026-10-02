<?php

namespace Tests\Feature;

use App\Notifications\PasswordResetRequestApprovedNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

/**
 * PHASE 1C — A-5: queued notifications that carry reset credentials must be
 * encrypted at rest.
 *
 * Why this file exists:
 *
 * Every secret-bearing notification in this project is expected to implement
 * `ShouldBeEncrypted` (the standard is set and documented by
 * VerifyEmailNotification: Laravel reads the marker in
 * SendQueuedNotifications → Queue::jobShouldBeEncrypted → createPayload, so
 * the serialized job is encrypted BEFORE it lands in `jobs.payload`).
 *
 * Two notifications carried reset URLs WITHOUT the marker:
 *
 *  - ResetPasswordNotification — constructed by
 *    User::sendPasswordResetNotification() (the CanResetPassword contract
 *    method). Its only caller is Laravel's password broker, which no code in
 *    this project invokes (recovery runs through the admin-approval flow),
 *    so the exposure is latent rather than live — but the dispatch site
 *    exists one hook away.
 *  - PasswordResetRequestApprovedNotification — constructed nowhere today
 *    (the approval flow has the admin set the password directly).
 *
 * "Currently unreachable" is not a safety property: the next change that
 * wires either path must not have to remember the interface. This test pins
 * it. It mirrors EmailVerificationTokenSecurityTest's payload assertion.
 *
 * The assertions are on the QUEUE CONTRACT (the marker + the ShouldQueue
 * pairing), which is exactly what the framework keys encryption on — the
 * behaviour is driver-independent, so the test is meaningful on both SQLite
 * and PostgreSQL runs.
 */
class ResetNotificationEncryptionTest extends TestCase
{
    public function test_reset_password_notification_is_queued_encrypted(): void
    {
        $notification = new ResetPasswordNotification('plain-reset-token');

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertInstanceOf(
            ShouldBeEncrypted::class,
            $notification,
            'A queued password-reset notification must carry ShouldBeEncrypted so the raw '
            .'reset token is never written to a readable jobs.payload column.'
        );
    }

    public function test_approved_reset_request_notification_is_queued_encrypted(): void
    {
        $notification = new PasswordResetRequestApprovedNotification(
            'https://example.test/reset-password?token=abc&email=user@example.com'
        );

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertInstanceOf(
            ShouldBeEncrypted::class,
            $notification,
            'A queued notification carrying a reset URL must carry ShouldBeEncrypted so the '
            .'URL is never written to a readable jobs.payload column.'
        );
    }
}
