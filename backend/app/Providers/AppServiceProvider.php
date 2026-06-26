<?php

namespace App\Providers;

use App\Contracts\AttendanceContextRepositoryInterface;
use App\Contracts\AttendanceContextServiceInterface;
use App\Contracts\AttendanceRepositoryInterface;
use App\Contracts\AttendanceServiceInterface;
use App\Contracts\AuditServiceInterface;
use App\Contracts\AuthServiceInterface;
use App\Contracts\ClasseRepositoryInterface;
use App\Contracts\ClasseServiceInterface;
use App\Contracts\EmailServiceInterface;
use App\Contracts\FileUploadServiceInterface;
use App\Contracts\LeaderboardServiceInterface;
use App\Contracts\StorageServiceInterface;
use App\Contracts\StageRepositoryInterface;
use App\Contracts\StageServiceInterface;
use App\Contracts\EventRepositoryInterface;
use App\Contracts\EventServiceInterface;
use App\Contracts\FeedbackRepositoryInterface;
use App\Contracts\FeedbackServiceInterface;
use App\Contracts\MembershipRequestRepositoryInterface;
use App\Contracts\MembershipRequestServiceInterface;
use App\Contracts\PasswordResetRequestServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\PointRepositoryInterface;
use App\Contracts\PointServiceInterface;
use App\Contracts\QRInviteRepositoryInterface;
use App\Contracts\QRInviteServiceInterface;
use App\Contracts\UserRepositoryInterface;
use App\Contracts\UserServiceInterface;
use App\Contracts\VerseRepositoryInterface;
use App\Contracts\VerseServiceInterface;
use App\Models\ChurchApplication;
use App\Models\Event;
use App\Models\User as UserModel;
use App\Observers\ChurchApplicationObserver;
use App\Observers\EventObserver;
use App\Observers\MembershipRequestObserver;
use App\Observers\UserObserver;
use App\Enums\UserRole;
use App\Listeners\InvalidateAttendanceCache;
use App\Models\Attendance;
use App\Models\AttendanceContext;
use App\Models\Church;
use App\Models\DailyVerse;
use App\Models\Feedback;
use App\Models\MembershipRequest as MembershipRequestModel;
use App\Models\PasswordResetRequest;
use App\Models\QRInvite;
use App\Models\User;
use App\Modules\User\Policies\UserPolicy;
use App\Modules\User\Repositories\UserRepository;
use App\Modules\User\Services\UserService;
use App\Policies\AttendanceContextPolicy;
use App\Policies\AttendancePolicy;
use App\Policies\ChurchDeletionPolicy;
use App\Policies\DailyVersePolicy;
use App\Policies\EventPolicy;
use App\Policies\FeedbackPolicy;
use App\Policies\PasswordResetRequestPolicy;
use App\Policies\QRInvitePolicy;
use App\Repositories\AttendanceContextRepository;
use App\Repositories\AttendanceRepository;
use App\Repositories\EventRepository;
use App\Repositories\FeedbackRepository;
use App\Repositories\MembershipRequestRepository;
use App\Repositories\PointRepository;
use App\Repositories\QRInviteRepository;
use App\Repositories\VerseRepository;
use App\Services\AttendanceContextService;
use App\Services\AttendanceService;
use App\Services\AuthService;
use App\Services\EmailService;
use App\Services\LeaderboardService;
use App\Services\CacheService;
use App\Services\EventService;
use App\Services\FeedbackService;
use App\Services\MembershipRequestService;
use App\Services\NotificationService;
use App\Services\PasswordResetRequestService;
use App\Services\PointService;
use App\Services\QRInviteService;
use App\Services\SupabaseStorageService;
use App\Services\VerseService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(QRInviteRepositoryInterface::class, QRInviteRepository::class);
        $this->app->bind(AttendanceRepositoryInterface::class, AttendanceRepository::class);
        $this->app->bind(PointRepositoryInterface::class, PointRepository::class);
        $this->app->bind(StageRepositoryInterface::class, \App\Repositories\StageRepository::class);
        $this->app->bind(ClasseRepositoryInterface::class, \App\Repositories\ClasseRepository::class);

        $this->app->bind(AuthServiceInterface::class, AuthService::class);
        $this->app->bind(QRInviteServiceInterface::class, QRInviteService::class);
        $this->app->bind(AttendanceServiceInterface::class, AttendanceService::class);
        $this->app->bind(PointServiceInterface::class, PointService::class);
        $this->app->bind(UserServiceInterface::class, UserService::class);

        $this->app->bind(StageServiceInterface::class, \App\Services\StageService::class);
        $this->app->bind(ClasseServiceInterface::class, \App\Services\ClasseService::class);
        $this->app->bind(LeaderboardServiceInterface::class, LeaderboardService::class);

        $this->app->bind(EventRepositoryInterface::class, EventRepository::class);
        $this->app->bind(EventServiceInterface::class, EventService::class);

        $this->app->bind(FeedbackRepositoryInterface::class, FeedbackRepository::class);
        $this->app->bind(FeedbackServiceInterface::class, FeedbackService::class);

        $this->app->bind(VerseRepositoryInterface::class, VerseRepository::class);
        $this->app->bind(VerseServiceInterface::class, VerseService::class);

        $this->app->bind(AttendanceContextRepositoryInterface::class, AttendanceContextRepository::class);
        $this->app->bind(AttendanceContextServiceInterface::class, AttendanceContextService::class);

        $this->app->bind(MembershipRequestRepositoryInterface::class, MembershipRequestRepository::class);
        $this->app->bind(MembershipRequestServiceInterface::class, MembershipRequestService::class);

        $this->app->bind(PasswordResetRequestServiceInterface::class, PasswordResetRequestService::class);

        $this->app->singleton(CacheService::class, fn() => new CacheService());

        $this->app->bind(AuditServiceInterface::class, \App\Services\AuditService::class);

        $this->app->bind(FileUploadServiceInterface::class, \App\Services\FileUploadService::class);

        $this->app->bind(StorageServiceInterface::class, SupabaseStorageService::class);

        $this->app->register(\Resend\Laravel\ResendServiceProvider::class);

        $this->app->bind(EmailServiceInterface::class, EmailService::class);

        $this->app->bind(NotificationServiceInterface::class, NotificationService::class);
    }

    public function boot(): void
    {
        // Force root URL so url() / URL::temporarySignedRoute() use APP_URL
        // instead of the request's Host header (which becomes 'nginx' behind Vite proxy)
        if ($rootUrl = config('app.url')) {
            $this->app['url']->forceRootUrl($rootUrl);
        }

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(QRInvite::class, QRInvitePolicy::class);
        Gate::policy(Attendance::class, AttendancePolicy::class);
        Gate::policy(Event::class, EventPolicy::class);
        Gate::policy(Feedback::class, FeedbackPolicy::class);
        Gate::policy(DailyVerse::class, DailyVersePolicy::class);
        Gate::policy(AttendanceContext::class, AttendanceContextPolicy::class);
        Gate::policy(PasswordResetRequest::class, PasswordResetRequestPolicy::class);
        Gate::policy(Church::class, ChurchDeletionPolicy::class);

        // ──────────────────────────────────────────────
        // Model Observers — File Cleanup on Delete
        // ──────────────────────────────────────────────
        UserModel::observe(UserObserver::class);
        Event::observe(EventObserver::class);
        ChurchApplication::observe(ChurchApplicationObserver::class);
        MembershipRequestModel::observe(MembershipRequestObserver::class);

        // ──────────────────────────────────────────────
        // Set default mailer to resend (overrides log in dev)
        // ──────────────────────────────────────────────
        if (config('mail.default') === 'log' && env('RESEND_API_KEY')) {
            Mail::alwaysFrom(
                config('mail.from.address'),
                config('mail.from.name')
            );
        }

        // ──────────────────────────────────────────────
        // Event → Listener Registrations
        // ──────────────────────────────────────────────
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\AttendanceRecorded::class,
            InvalidateAttendanceCache::class,
        );

        // Ensure storage symlink exists for public file access
        if (! file_exists(public_path('storage'))) {
            try {
                symlink(storage_path('app/public'), public_path('storage'));
            } catch (\Exception $e) {
                // Symlink creation failed - admin may need to run 'php artisan storage:link' manually
                // This is non-critical for functionality
            }
        }

        /*
        |--------------------------------------------------------------------------
        | API Rate Limiters
        |--------------------------------------------------------------------------
        |
        | Named rate limiters used across all API routes.
        | Uses authenticated user ID when available, falls back to IP.
        |
        */

        // Authenticated general — 300 req/min per user
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(300)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Guest general — 60 req/min per IP
        RateLimiter::for('guest', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Login — 5 attempts/min per IP/email combo
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip() . '|' . ($request->input('email') ?: 'guest'))
                ->response(fn() => self::rateLimitResponse());
        });

        // Email verification — 10 attempts/minute per IP
        RateLimiter::for('verify-email', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Register — 10 registrations/hour per IP
        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(10)
                ->by($request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Search — 60 req/min per authenticated user
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Invite generate — 20 invites/hour per admin
        RateLimiter::for('invite-generate', function (Request $request) {
            return Limit::perHour(20)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Invite validate/details — 10 req/min per IP
        RateLimiter::for('invite-public', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Invite accept — 10 attempts/hour per invite token
        RateLimiter::for('invite-accept', function (Request $request) {
            return Limit::perHour(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // User create — 30 req/min per user
        RateLimiter::for('user-create', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // User update — 60 req/min per user
        RateLimiter::for('user-update', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // User delete — 10 req/min per user
        RateLimiter::for('user-delete', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // User list — 30 req/min per user (search + listing)
        RateLimiter::for('user-list', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Attendance record — 100 req/min per user
        RateLimiter::for('attendance-record', function (Request $request) {
            return Limit::perMinute(100)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Attendance read (history, stats, today, by-class) — 60 req/min
        RateLimiter::for('attendance-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Attendance bulk import — 5 uploads/hour per user
        RateLimiter::for('attendance-bulk', function (Request $request) {
            return Limit::perHour(5)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // File upload — 10 uploads/min per user
        RateLimiter::for('file-upload', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // File import — 5 imports/hour per user
        RateLimiter::for('file-import', function (Request $request) {
            return Limit::perHour(5)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Structure CRUD — 30 req/min per user
        RateLimiter::for('structure-crud', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Structure read — 60 req/min per user
        RateLimiter::for('structure-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Event CRUD — 30 req/min per user
        RateLimiter::for('event-crud', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Event read — 60 req/min per user
        RateLimiter::for('event-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Feedback submit — 10 req/min per user
        RateLimiter::for('feedback-submit', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Feedback read — 60 req/min per user
        RateLimiter::for('feedback-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Notification read — 60 req/min per user
        RateLimiter::for('notification-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Notification send — 20 req/min per admin
        RateLimiter::for('notification-send', function (Request $request) {
            return Limit::perMinute(20)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Notification bulk — 5 req/hour per admin
        RateLimiter::for('notification-bulk', function (Request $request) {
            return Limit::perHour(5)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Points read — 60 req/min
        RateLimiter::for('points-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Analytics — 30 req/min per admin
        RateLimiter::for('analytics', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Sensitive admin operations (promote, demote, delete) — 10 req/min
        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // QR token regeneration — 5 req/hour per user
        RateLimiter::for('qr-regenerate', function (Request $request) {
            return Limit::perHour(5)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Verse CRUD — 30 req/min per user
        RateLimiter::for('verse-crud', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Verse read — 60 req/min per user
        RateLimiter::for('verse-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Membership request submit — 3 requests/hour per IP
        RateLimiter::for('membership-request', function (Request $request) {
            return Limit::perHour(3)
                ->by($request->ip() . '|' . ($request->input('email') ?: 'guest'))
                ->response(fn() => self::rateLimitResponse());
        });

        // Attendance context CRUD — 30 req/min per user
        RateLimiter::for('attendance-context-crud', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Email sending — 30 emails/min per user (prevents abuse via Resend)
        RateLimiter::for('email-send', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });

        // Storage upload — 10 uploads/min per user
        RateLimiter::for('storage-upload', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn() => self::rateLimitResponse());
        });
    }

    /**
     * Generate a standardized 429 response with Retry-After header.
     */
    private static function rateLimitResponse(): \Illuminate\Http\JsonResponse
    {
        $retryAfter = 60;

        return response()->json([
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $retryAfter,
        ], 429, ['Retry-After' => $retryAfter]);
    }
}
