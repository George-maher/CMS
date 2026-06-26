<?php

namespace App\Services;

use App\Contracts\EmailServiceInterface;
use App\Jobs\SendEmailJob;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class EmailService implements EmailServiceInterface
{
    public function sendPasswordReset(User $user, string $token): void
    {
        $frontendUrl = config('app.frontend_url');
        $resetUrl = $frontendUrl . '/reset-password?' . http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]);

        dispatch(new SendEmailJob(
            user: $user,
            subjectText: $user->preferredLocale() === 'ar'
                ? 'إعادة تعيين كلمة المرور'
                : 'Reset Your Password',
            viewName: 'emails.password-reset',
            viewData: ['resetUrl' => $resetUrl, 'token' => $token],
        ));
    }

    public function sendInviteEmail(User $user, string $inviteUrl): void
    {
        dispatch(new SendEmailJob(
            user: $user,
            subjectText: $user->preferredLocale() === 'ar'
                ? 'دعوة للانضمام إلى نظام إدارة الكنيسة'
                : 'Invitation to Join Church Management System',
            viewName: 'emails.invite',
            viewData: ['inviteUrl' => $inviteUrl],
        ));
    }

    public function sendNotification(User $user, string $subject, string $content): void
    {
        dispatch(new SendEmailJob(
            user: $user,
            subjectText: $subject,
            viewName: 'emails.notification',
            viewData: ['content' => $content],
        ));
    }

    public function sendFeedbackReply(User $user, string $feedbackMessage, string $replyMessage): void
    {
        dispatch(new SendEmailJob(
            user: $user,
            subjectText: $user->preferredLocale() === 'ar'
                ? 'تم الرد على ملاحظاتك'
                : 'Your Feedback Has a Reply',
            viewName: 'emails.feedback-reply',
            viewData: [
                'feedbackMessage' => $feedbackMessage,
                'replyMessage' => $replyMessage,
            ],
        ));
    }

    public function sendWelcomeEmail(User $user): void
    {
        $loginUrl = config('app.frontend_url') . '/login';

        dispatch(new SendEmailJob(
            user: $user,
            subjectText: $user->preferredLocale() === 'ar'
                ? 'مرحباً بك في نظام إدارة الكنيسة'
                : 'Welcome to Church Management System',
            viewName: 'emails.welcome',
            viewData: ['loginUrl' => $loginUrl],
        ));
    }

    public function sendRegistrationSubmitted(User $user): void
    {
        dispatch(new SendEmailJob(
            user: $user,
            subjectText: $user->preferredLocale() === 'ar'
                ? 'تم تقديم طلب التسجيل'
                : 'Registration Submitted Successfully',
            viewName: 'emails.registration-submitted',
            viewData: [],
        ));
    }

    public function sendApplicationApproved(User $user, string $churchName): void
    {
        $dashboardUrl = config('app.frontend_url') . '/admin';

        dispatch(new SendEmailJob(
            user: $user,
            subjectText: $user->preferredLocale() === 'ar'
                ? 'تم الموافقة على طلب تسجيل الكنيسة'
                : 'Your Church Registration Has Been Approved',
            viewName: 'emails.application-approved',
            viewData: [
                'churchName' => $churchName,
                'dashboardUrl' => $dashboardUrl,
            ],
        ));
    }

    public function sendApplicationRejected(User $user, string $reason): void
    {
        dispatch(new SendEmailJob(
            user: $user,
            subjectText: $user->preferredLocale() === 'ar'
                ? 'تحديث طلب تسجيل الكنيسة'
                : 'Church Registration Update',
            viewName: 'emails.application-rejected',
            viewData: ['reason' => $reason],
        ));
    }

    public function sendAttendanceNotification(User $user, string $className, string $date): void
    {
        dispatch(new SendEmailJob(
            user: $user,
            subjectText: $user->preferredLocale() === 'ar'
                ? 'تم تسجيل حضورك'
                : 'Attendance Recorded',
            viewName: 'emails.attendance-notification',
            viewData: [
                'className' => $className,
                'date' => $date,
            ],
        ));
    }

    public function sendEventNotification(User $user, string $eventName, string $eventDate, string $eventUrl): void
    {
        dispatch(new SendEmailJob(
            user: $user,
            subjectText: $user->preferredLocale() === 'ar'
                ? 'حدث جديد: ' . $eventName
                : 'New Event: ' . $eventName,
            viewName: 'emails.event-notification',
            viewData: [
                'eventName' => $eventName,
                'eventDate' => $eventDate,
                'eventUrl' => $eventUrl,
            ],
        ));
    }
}
