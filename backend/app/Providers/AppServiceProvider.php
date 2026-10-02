<?php

namespace App\Providers;

use App\Contracts\AttendanceContextRepositoryInterface;
use App\Contracts\AttendanceContextServiceInterface;
use App\Contracts\AttendanceRepositoryInterface;
use App\Contracts\AttendanceServiceInterface;
use App\Contracts\AuditServiceInterface;
use App\Contracts\AuthServiceInterface;
use App\Contracts\ChurchApplicationServiceInterface;
use App\Contracts\ClasseRepositoryInterface;
use App\Contracts\ClasseServiceInterface;
use App\Contracts\DailySpiritualRecordServiceInterface;
use App\Contracts\EmailServiceInterface;
use App\Contracts\EmailVerificationServiceInterface;
use App\Contracts\EventAccommodationServiceInterface;
use App\Contracts\EventLifecycleServiceInterface;
use App\Contracts\EventPaymentServiceInterface;
use App\Contracts\EventRegistrationRepositoryInterface;
use App\Contracts\EventRegistrationServiceInterface;
use App\Contracts\EventReportServiceInterface;
use App\Contracts\EventRepositoryInterface;
use App\Contracts\EventReservationServiceInterface;
use App\Contracts\EventScheduleServiceInterface;
use App\Contracts\EventServiceInterface;
use App\Contracts\FeedbackRepositoryInterface;
use App\Contracts\FeedbackServiceInterface;
use App\Contracts\FileUploadServiceInterface;
use App\Contracts\LeaderboardServiceInterface;
use App\Contracts\MailConfigurationValidatorInterface;
use App\Contracts\MemberProfileServiceInterface;
use App\Contracts\MembershipRequestRepositoryInterface;
use App\Contracts\MembershipRequestServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\PasswordResetRequestServiceInterface;
use App\Contracts\PointRepositoryInterface;
use App\Contracts\PointServiceInterface;
use App\Contracts\ProfileUpdateRequestServiceInterface;
use App\Contracts\QRInviteRepositoryInterface;
use App\Contracts\QRInviteServiceInterface;
use App\Contracts\ScopeResolverInterface;
use App\Contracts\StageRepositoryInterface;
use App\Contracts\StageServiceInterface;
use App\Contracts\StorageServiceInterface;
use App\Contracts\UserProvisioningServiceInterface;
use App\Contracts\UserRepositoryInterface;
use App\Contracts\UserServiceInterface;
use App\Contracts\VerseRepositoryInterface;
use App\Contracts\VerseServiceInterface;
use App\Events\AttendanceRecorded;
use App\Listeners\InvalidateAttendanceCache;
use App\Models\Attendance;
use App\Models\AttendanceContext;
use App\Models\Church;
use App\Models\ChurchApplication;
use App\Models\Classe;
use App\Models\DailySpiritualRecord;
use App\Models\DailyVerse;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\MembershipRequest as MembershipRequestModel;
use App\Models\PasswordResetRequest;
use App\Models\ProfileUpdateRequest;
use App\Models\QRInvite;
use App\Models\Stage;
use App\Models\User;
use App\Models\User as UserModel;
use App\Modules\User\Policies\UserPolicy;
use App\Modules\User\Repositories\UserRepository;
use App\Modules\User\Services\UserService;
use App\Observers\ChurchApplicationObserver;
use App\Observers\EventObserver;
use App\Observers\MembershipRequestObserver;
use App\Observers\UserObserver;
use App\Policies\AttendanceContextPolicy;
use App\Policies\AttendancePolicy;
use App\Policies\ChurchDeletionPolicy;
use App\Policies\ClassePolicy;
use App\Policies\DailySpiritualRecordPolicy;
use App\Policies\DailyVersePolicy;
use App\Policies\EventPolicy;
use App\Policies\FeedbackPolicy;
use App\Policies\PasswordResetRequestPolicy;
use App\Policies\ProfileUpdateRequestPolicy;
use App\Policies\QRInvitePolicy;
use App\Policies\StagePolicy;
use App\Repositories\AttendanceContextRepository;
use App\Repositories\AttendanceRepository;
use App\Repositories\ClasseRepository;
use App\Repositories\EventRegistrationRepository;
use App\Repositories\EventRepository;
use App\Repositories\FeedbackRepository;
use App\Repositories\MembershipRequestRepository;
use App\Repositories\PointRepository;
use App\Repositories\QRInviteRepository;
use App\Repositories\StageRepository;
use App\Repositories\VerseRepository;
use App\Services\AttendanceContextService;
use App\Services\AttendanceService;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\CacheService;
use App\Services\ChurchApplicationService;
use App\Services\ClasseService;
use App\Services\DailySpiritualRecordService;
use App\Services\EmailService;
use App\Services\EmailVerificationService;
use App\Services\EventAccommodationService;
use App\Services\EventLifecycleService;
use App\Services\EventPaymentService;
use App\Services\EventRegistrationService;
use App\Services\EventReportService;
use App\Services\EventReservationService;
use App\Services\EventScheduleService;
use App\Services\EventService;
use App\Services\FeedbackService;
use App\Services\FileUploadService;
use App\Services\LeaderboardService;
use App\Services\LocalStorageService;
use App\Services\MailConfigurationValidator;
use App\Services\MemberProfileService;
use App\Services\MembershipRequestService;
use App\Services\NotificationService;
use App\Services\PasswordResetRequestService;
use App\Services\PointService;
use App\Services\ProfileUpdateRequestService;
use App\Services\QRInviteService;
use App\Services\ScopeResolver;
use App\Services\StageService;
use App\Services\SupabaseStorageService;
use App\Services\UserProvisioningService;
use App\Services\VerseService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
        $this->app->bind(StageRepositoryInterface::class, StageRepository::class);
        $this->app->bind(ClasseRepositoryInterface::class, ClasseRepository::class);

        $this->app->bind(AuthServiceInterface::class, AuthService::class);
        $this->app->bind(QRInviteServiceInterface::class, QRInviteService::class);
        $this->app->bind(AttendanceServiceInterface::class, AttendanceService::class);
        $this->app->bind(PointServiceInterface::class, PointService::class);
        $this->app->bind(UserServiceInterface::class, UserService::class);

        $this->app->bind(StageServiceInterface::class, StageService::class);
        $this->app->bind(ClasseServiceInterface::class, ClasseService::class);
        $this->app->bind(LeaderboardServiceInterface::class, LeaderboardService::class);

        $this->app->bind(EventRepositoryInterface::class, EventRepository::class);
        $this->app->bind(EventServiceInterface::class, EventService::class);
        $this->app->bind(EventRegistrationRepositoryInterface::class, EventRegistrationRepository::class);
        $this->app->bind(EventRegistrationServiceInterface::class, EventRegistrationService::class);
        $this->app->bind(EventPaymentServiceInterface::class, EventPaymentService::class);
        $this->app->bind(EventLifecycleServiceInterface::class, EventLifecycleService::class);
        $this->app->bind(EventScheduleServiceInterface::class, EventScheduleService::class);
        $this->app->bind(EventReportServiceInterface::class, EventReportService::class);
        $this->app->bind(EventAccommodationServiceInterface::class, EventAccommodationService::class);
        $this->app->bind(EventReservationServiceInterface::class, EventReservationService::class);

        $this->app->bind(FeedbackRepositoryInterface::class, FeedbackRepository::class);
        $this->app->bind(FeedbackServiceInterface::class, FeedbackService::class);

        $this->app->bind(VerseRepositoryInterface::class, VerseRepository::class);
        $this->app->bind(VerseServiceInterface::class, VerseService::class);

        $this->app->bind(AttendanceContextRepositoryInterface::class, AttendanceContextRepository::class);
        $this->app->bind(AttendanceContextServiceInterface::class, AttendanceContextService::class);

        $this->app->bind(MembershipRequestRepositoryInterface::class, MembershipRequestRepository::class);
        $this->app->bind(MembershipRequestServiceInterface::class, MembershipRequestService::class);

        $this->app->bind(PasswordResetRequestServiceInterface::class, PasswordResetRequestService::class);

        $this->app->bind(ProfileUpdateRequestServiceInterface::class, ProfileUpdateRequestService::class);

        $this->app->bind(MemberProfileServiceInterface::class, MemberProfileService::class);

        $this->app->bind(ScopeResolverInterface::class, ScopeResolver::class);

        $this->app->singleton(CacheService::class, fn () => new CacheService);

        $this->app->bind(ChurchApplicationServiceInterface::class, ChurchApplicationService::class);
        $this->app->bind(DailySpiritualRecordServiceInterface::class, DailySpiritualRecordService::class);

        $this->app->bind(AuditServiceInterface::class, AuditService::class);
        $this->app->bind(FileUploadServiceInterface::class, FileUploadService::class);

        $this->app->bind(StorageServiceInterface::class, function () {
            /** @var string $url */
            $url = config('supabase-storage.project_url', '');
            /** @var string $key */
            $key = config('supabase-storage.service_role_key', '');
            if ($url !== '' && $key !== '') {
                return new SupabaseStorageService;
            }

            return new LocalStorageService;
        });

        $this->app->bind(EmailServiceInterface::class, EmailService::class);

        $this->app->bind(NotificationServiceInterface::class, NotificationService::class);

        $this->app->bind(UserProvisioningServiceInterface::class, UserProvisioningService::class);

        $this->app->bind(MailConfigurationValidatorInterface::class, MailConfigurationValidator::class);
        $this->app->bind(EmailVerificationServiceInterface::class, EmailVerificationService::class);
    }

    public function boot(): void
    {
        /** @var string|null $rootUrl */
        $rootUrl = config('app.url');
        if ($rootUrl !== null && $rootUrl !== '') {
            /** @var UrlGenerator $url */
            $url = $this->app->make('url');
            $url->forceRootUrl($rootUrl);
        }

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(QRInvite::class, QRInvitePolicy::class);
        Gate::policy(Attendance::class, AttendancePolicy::class);
        Gate::policy(Event::class, EventPolicy::class);
        Gate::policy(Feedback::class, FeedbackPolicy::class);
        Gate::policy(DailyVerse::class, DailyVersePolicy::class);
        Gate::policy(AttendanceContext::class, AttendanceContextPolicy::class);
        Gate::policy(PasswordResetRequest::class, PasswordResetRequestPolicy::class);
        Gate::policy(ProfileUpdateRequest::class, ProfileUpdateRequestPolicy::class);
        Gate::policy(Church::class, ChurchDeletionPolicy::class);
        Gate::policy(DailySpiritualRecord::class, DailySpiritualRecordPolicy::class);
        Gate::policy(Stage::class, StagePolicy::class);
        Gate::policy(Classe::class, ClassePolicy::class);

        // ──────────────────────────────────────────────
        // Model Observers — File Cleanup on Delete
        // ──────────────────────────────────────────────
        UserModel::observe(UserObserver::class);
        Event::observe(EventObserver::class);
        ChurchApplication::observe(ChurchApplicationObserver::class);
        MembershipRequestModel::observe(MembershipRequestObserver::class);

        // ──────────────────────────────────────────────
        // Event → Listener Registrations
        // ──────────────────────────────────────────────
        \Illuminate\Support\Facades\Event::listen(
            AttendanceRecorded::class,
            InvalidateAttendanceCache::class,
        );

        // Ensure storage symlink exists for public file access
        // Required for any file upload (church applications, events, profiles) to be accessible
        $linkPath = public_path('storage');
        $targetPath = storage_path('app/public');
        if (! file_exists($linkPath) && is_dir($targetPath)) {
            try {
                symlink($targetPath, $linkPath);
            } catch (\Exception $e) {
                // Symlink creation failed - 'php artisan storage:link' must be run manually
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
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Guest general — 60 req/min per IP
        RateLimiter::for('guest', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Login — 5 attempts/min per IP/email combo
        RateLimiter::for('login', function (Request $request) {
            $loginIp = (string) $request->ip();
            $emailInput = $request->input('email');
            $loginEmail = is_string($emailInput) ? $emailInput : 'guest';

            return Limit::perMinute(5)
                ->by($loginIp.'|'.$loginEmail)
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Email verification — 10 attempts/minute per IP
        RateLimiter::for('verify-email', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Register — 10 registrations/hour per IP
        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(10)
                ->by($request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Search — 60 req/min per authenticated user
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Invite generate — 3 invites/minute per user (prevents rapid duplicate creation)
        RateLimiter::for('invite-generate', function (Request $request) {
            return Limit::perMinute(3)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Invite validate/details — 10 req/min per IP
        RateLimiter::for('invite-public', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Invite accept — 10 attempts/hour per invite token
        RateLimiter::for('invite-accept', function (Request $request) {
            return Limit::perHour(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // User create — 30 req/min per user
        RateLimiter::for('user-create', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // User update — 60 req/min per user
        RateLimiter::for('user-update', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // User delete — 10 req/min per user
        RateLimiter::for('user-delete', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // User list — 30 req/min per user (search + listing)
        RateLimiter::for('user-list', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Attendance record — 100 req/min per user
        RateLimiter::for('attendance-record', function (Request $request) {
            return Limit::perMinute(100)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Attendance read (history, stats, today, by-class) — 60 req/min
        RateLimiter::for('attendance-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Attendance bulk import — 5 uploads/hour per user
        RateLimiter::for('attendance-bulk', function (Request $request) {
            return Limit::perHour(5)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // File upload — 10 uploads/min per user
        RateLimiter::for('file-upload', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // File import — 5 imports/hour per user
        RateLimiter::for('file-import', function (Request $request) {
            return Limit::perHour(5)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Structure CRUD — 30 req/min per user
        RateLimiter::for('structure-crud', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Structure read — 60 req/min per user
        RateLimiter::for('structure-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Event CRUD — 30 req/min per user
        RateLimiter::for('event-crud', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Event read — 60 req/min per user
        RateLimiter::for('event-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Feedback submit — 10 req/min per user
        RateLimiter::for('feedback-submit', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Feedback read — 60 req/min per user
        RateLimiter::for('feedback-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Notification read — 60 req/min per user
        RateLimiter::for('notification-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Notification send — 20 req/min per admin
        RateLimiter::for('notification-send', function (Request $request) {
            return Limit::perMinute(20)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Notification bulk — 5 req/hour per admin
        RateLimiter::for('notification-bulk', function (Request $request) {
            return Limit::perHour(5)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Points read — 60 req/min
        RateLimiter::for('points-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Analytics — 30 req/min per admin
        RateLimiter::for('analytics', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Sensitive admin operations (promote, demote, delete) — 10 req/min
        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // QR token regeneration — 5 req/hour per user
        RateLimiter::for('qr-regenerate', function (Request $request) {
            return Limit::perHour(5)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Verse CRUD — 30 req/min per user
        RateLimiter::for('verse-crud', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Verse read — 60 req/min per user
        RateLimiter::for('verse-read', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Membership request submit — 3 requests/hour per IP
        RateLimiter::for('membership-request', function (Request $request) {
            $membershipIp = (string) $request->ip();
            $emailInput = $request->input('email');
            $membershipEmail = is_string($emailInput) ? $emailInput : 'guest';

            return Limit::perHour(3)
                ->by($membershipIp.'|'.$membershipEmail)
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Attendance context CRUD — 30 req/min per user
        RateLimiter::for('attendance-context-crud', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Spiritual record CRUD — 60 req/min per user
        RateLimiter::for('spiritual-record', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Email sending — 30 emails/min per user (prevents abuse)
        RateLimiter::for('email-send', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Storage upload — 10 uploads/min per user
        RateLimiter::for('storage-upload', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));
        });

        // Fix: FormRequest's failedValidation() calls getRedirectUrl() which needs
        // the redirector. This is null in test environments, causing a 500 instead of
        // a proper 422 validation error response. Inject it when the FormRequest is resolved.
        $this->app->resolving(FormRequest::class, function (FormRequest $request, Application $app): void {
            if ($app->has('redirect')) {
                /** @var Redirector $redirector */
                $redirector = $app->make('redirect');
                $request->setRedirector($redirector);
            }
        });
    }

    /**
     * Generate the 429 response for a named rate limiter.
     *
     * PHASE 1C — P0 FIX.
     *
     * Laravel invokes a limit's response callback POSITIONALLY with two
     * arguments — see Illuminate\Routing\Middleware\ThrottleRequests::buildException():
     *
     *     new HttpResponseException($responseCallback($request, $headers))
     *
     * The callback was previously declared `fn () => ...`, which silently
     * discarded both arguments. That meant the response could not carry the
     * real Retry-After computed for the limiter, could not carry the
     * X-RateLimit-* headers Laravel had already built, and emitted a body that
     * did not match the JSON error contract every other API error uses.
     *
     * The body now mirrors the ThrottleRequestsException renderer in
     * bootstrap/app.php, so BOTH throttle paths return one identical contract
     * regardless of whether a limiter declares ->response().
     *
     * `$headers` is deliberately documented as a bare `array` so it matches the
     * type PHPStan infers for the untyped closure parameter at the 38 call
     * sites; every value is validated before use below.
     *
     * @param  array  $headers  Header map produced by ThrottleRequests::getHeaders().
     */
    private static function rateLimitResponse(Request $request, array $headers): JsonResponse
    {
        $retryAfter = self::headerInt($headers['Retry-After'] ?? null) ?? 60;

        // Replaces the previous (erroneous) ERROR-level "Unhandled API
        // exception" record so routine throttling no longer fires 5xx alerting.
        Log::warning('Rate limit exceeded', [
            'request_id' => $request->attributes->get('request_id'),
            'ip' => $request->ip(),
            'path' => $request->path(),
            'method' => $request->method(),
            'user_id' => $request->user()?->id,
            'user_agent' => $request->userAgent(),
            'retry_after' => $retryAfter,
        ]);

        $responseHeaders = ['Retry-After' => (string) $retryAfter];

        foreach (['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'] as $headerName) {
            $headerValue = self::headerInt($headers[$headerName] ?? null);

            if ($headerValue !== null) {
                $responseHeaders[$headerName] = (string) $headerValue;
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $retryAfter,
            'code' => 'RATE_LIMITED',
        ], 429, $responseHeaders);
    }

    /**
     * Narrow a value taken from the untyped throttle header map to an int.
     *
     * The closure parameter at every ->response() call site is untyped, so
     * PHPStan infers a plain `array` and each value reaching this provider is
     * `mixed`. Validating here keeps level-max analysis honest instead of
     * silencing it with a cast.
     *
     * Returns null when the value is absent or not a non-negative integer,
     * so callers decide whether a missing header is acceptable.
     */
    private static function headerInt(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
