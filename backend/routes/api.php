<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\AttendanceContextController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ChurchApplicationController;
use App\Http\Controllers\Api\ChurchDeletionController;
use App\Http\Controllers\Api\ClasseController;
use App\Http\Controllers\Api\DailyVerseController;
use App\Http\Controllers\Api\EventAnalyticsController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\MembershipRequestController;
use App\Http\Controllers\Api\PasswordResetRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PendingDashboardController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\StorageController;
use App\Http\Controllers\Api\PointController;
use App\Http\Controllers\Api\QRInviteController;
use App\Http\Controllers\Api\StageController;
use App\Http\Controllers\Api\StructureController;
use App\Modules\User\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    /*
    | Platform Admin Secret Login — path configured via PLATFORM_ADMIN_LOGIN_PATH env
    */
    $platformAdminPath = config('services.platform_admin_login_path', 'platform-secure-admin-login');
    Route::post('/auth/' . $platformAdminPath, [AuthController::class, 'platformLogin'])
        ->middleware('throttle:login')
        ->name('platform.admin.login');

    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:register');

    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:login');

    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:login');

    /*
    | Password Reset Requests — public (member/servant submits, user completes reset)
    */
    Route::post('/password-reset-requests', [PasswordResetRequestController::class, 'submit'])
        ->middleware('throttle:login');

    Route::post('/password-reset-requests/reset', [PasswordResetRequestController::class, 'completeReset'])
        ->middleware('throttle:login');

    Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail'])
        ->middleware('throttle:verify-email');
    Route::post('/auth/resend-verification', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:verify-email');

    Route::get('/qr/validate/{token}', [QRInviteController::class, 'validateToken'])
        ->middleware('throttle:invite-public');

    Route::get('/invite/{token}', [QRInviteController::class, 'details'])
        ->middleware('throttle:invite-public');

    /*
    | Daily Verse — public
    */
    Route::get('/verses/active', [DailyVerseController::class, 'getActive'])
        ->middleware('throttle:verse-read');

    /*
    | Church Applications — public registration
    */
    Route::post('/church-applications', [ChurchApplicationController::class, 'store'])
        ->middleware('throttle:register');

    /*
    | Active churches — public listing for join request form
    */
    Route::get('/churches/active', function () {
        $churches = \App\Models\Church::where('is_active', true)
            ->where('is_suspended', false)
            ->get(['id', 'name', 'slug', 'address']);

        return response()->json([
            'data' => $churches,
        ]);
    })->middleware('throttle:api');

});

/*
|--------------------------------------------------------------------------
| Authenticated Routes (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    /*
    | Auth
    */
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    /*
    | Storage — direct file uploads to Supabase
    */
    Route::post('/storage/upload/{bucket}', [StorageController::class, 'upload'])
        ->middleware('throttle:storage-upload');
    Route::post('/storage/upload-profile-image', [StorageController::class, 'uploadProfileImage'])
        ->middleware('throttle:storage-upload');
    Route::post('/storage/upload-event-image', [StorageController::class, 'uploadEventImage'])
        ->middleware('throttle:storage-upload');
    Route::post('/storage/upload-document', [StorageController::class, 'uploadDocument'])
        ->middleware('throttle:storage-upload');
    Route::post('/storage/replace/{bucket}', [StorageController::class, 'replaceFile'])
        ->middleware('throttle:storage-upload');
    Route::delete('/storage/delete/{bucket}', [StorageController::class, 'delete'])
        ->middleware('throttle:storage-upload');

    /*
    | Pending Dashboard — any authenticated user
    */
    Route::get('/pending/status', [PendingDashboardController::class, 'status']);

    /*
    | Dashboard stats — aggregate data for admin home
    */
    Route::get('/dashboard/stats', [DashboardController::class, 'stats'])
        ->middleware('throttle:api');

    /*
    | Points — read heavy
    */
    Route::get('/points/balance', [PointController::class, 'balance'])
        ->middleware('throttle:points-read');
    Route::get('/points/history', [PointController::class, 'history'])
        ->middleware('throttle:points-read');
    Route::get('/points/leaderboard', [PointController::class, 'leaderboard'])
        ->middleware('throttle:points-read');

    /*
    | Leaderboards — read heavy
    */
    Route::get('/leaderboard/global', [\App\Http\Controllers\Api\LeaderboardController::class, 'global'])
        ->middleware('throttle:points-read');
    Route::get('/leaderboard/my-class', [\App\Http\Controllers\Api\LeaderboardController::class, 'myClass'])
        ->middleware('throttle:points-read');
    Route::get('/leaderboard/my-classes', [\App\Http\Controllers\Api\LeaderboardController::class, 'myClasses'])
        ->middleware(['permission:view_users', 'throttle:points-read']);
    Route::get('/leaderboard/stages', [\App\Http\Controllers\Api\LeaderboardController::class, 'stages'])
        ->middleware(['permission:view_users', 'throttle:points-read']);
    Route::get('/leaderboard/class/{classId}', [\App\Http\Controllers\Api\LeaderboardController::class, 'byClass'])
        ->middleware(['permission:view_users', 'throttle:points-read']);

    /*
    | Attendances — read heavy
    */
    Route::get('/attendances/history/{userId?}', [AttendanceController::class, 'history'])
        ->middleware('throttle:attendance-read');
    Route::get('/attendances/stats/{userId?}', [AttendanceController::class, 'stats'])
        ->middleware('throttle:attendance-read');

    /*
        | Stages + Classes — read
        */
        Route::get('/stages', [StageController::class, 'index'])
            ->middleware('throttle:structure-read');
        Route::get('/stages/{id}', [StageController::class, 'show'])
            ->middleware('throttle:structure-read');
        Route::get('/stages/{id}/classes', [StageController::class, 'classes'])
            ->middleware('throttle:structure-read');

        Route::get('/classes', [ClasseController::class, 'index'])
            ->middleware('throttle:structure-read');
        Route::get('/classes/{id}', [ClasseController::class, 'show'])
            ->middleware('throttle:structure-read');

        /*
        | Structure — unified stages with classes grouped
        */
        Route::get('/structure/classes', [StructureController::class, 'classes'])
            ->middleware('throttle:structure-read');
        Route::get('/structure/my-classes', [StructureController::class, 'myClasses'])
            ->middleware('throttle:structure-read');
        Route::get('/structure/my-class-servants', [StructureController::class, 'myClassServants'])
            ->middleware('throttle:structure-read');
        Route::get('/structure/stages-with-classes', [StructureController::class, 'stagesWithClasses'])
            ->middleware('throttle:structure-read');

    /*
    | Events — read
    */
    Route::get('/events', [EventController::class, 'index'])
        ->middleware('throttle:event-read');
    Route::get('/events/{id}', [EventController::class, 'show'])
        ->middleware('throttle:event-read');
    Route::post('/events/{id}/track-view', [EventAnalyticsController::class, 'track'])
        ->middleware('throttle:event-read');

    /*
    | QR Invite Accept
    */
    Route::post('/invite/{token}/accept', [QRInviteController::class, 'accept'])
        ->middleware(['permission:manage_invites,record_attendance', 'throttle:invite-accept']);

    /*
    | Notifications — any authenticated user
    */
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->middleware('throttle:notification-read');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->middleware('throttle:notification-read');
    Route::post('/notifications/{id}/mark-read', [NotificationController::class, 'markAsRead'])
        ->middleware('throttle:notification-read');
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])
        ->middleware('throttle:notification-read');

    /*
    | Attendance Contexts — read (active list, no auth restriction)
    */
    Route::get('/attendance-contexts', [AttendanceContextController::class, 'active']);

        /*
        | Daily Verse — read
    */
    Route::get('/verses', [DailyVerseController::class, 'index'])
        ->middleware('throttle:verse-read');
    Route::get('/verses/{id}', [DailyVerseController::class, 'show'])
        ->middleware('throttle:verse-read');

    /*
    | Feedback — member submit, any read own
    */
    Route::post('/feedback', [FeedbackController::class, 'submit'])
        ->middleware(['permission:submit_feedback', 'throttle:feedback-submit']);
    Route::get('/feedback/mine', [FeedbackController::class, 'myFeedback'])
        ->middleware('throttle:feedback-read');
    Route::post('/feedback/{id}/mark-seen', [FeedbackController::class, 'markSeen'])
        ->middleware('throttle:feedback-read');

    /*
    |--------------------------------------------------------------------------
    | Servant + Admin Routes (permission: view_users)
    |--------------------------------------------------------------------------
    */

    Route::middleware(['permission:view_users', 'approved'])->group(function () {

        /*
        | Events — CRUD
        */
        Route::post('/events', [EventController::class, 'store'])
            ->middleware('throttle:event-crud');
        Route::put('/events/{id}', [EventController::class, 'update'])
            ->middleware('throttle:event-crud');
        Route::patch('/events/{id}', [EventController::class, 'update'])
            ->middleware('throttle:event-crud');
        Route::delete('/events/{id}', [EventController::class, 'destroy'])
            ->middleware('throttle:event-crud');

        /*
        | Event Analytics — view_users permission
        */
        Route::get('/events/{id}/analytics/summary', [EventAnalyticsController::class, 'summary'])
            ->middleware('throttle:event-read');
        Route::get('/events/{id}/analytics/viewed', [EventAnalyticsController::class, 'viewed'])
            ->middleware('throttle:event-read');
        Route::get('/events/{id}/analytics/not-viewed', [EventAnalyticsController::class, 'notViewed'])
            ->middleware('throttle:event-read');

        /*
        | Daily Verse — CRUD + activate
        */
        Route::post('/verses', [DailyVerseController::class, 'store'])
            ->middleware('throttle:verse-crud');
        Route::put('/verses/{id}', [DailyVerseController::class, 'update'])
            ->middleware('throttle:verse-crud');
        Route::delete('/verses/{id}', [DailyVerseController::class, 'destroy'])
            ->middleware('throttle:verse-crud');
        Route::post('/verses/{id}/activate', [DailyVerseController::class, 'activate'])
            ->middleware('throttle:verse-crud');

        /*
        | QR Invites
        */
        Route::post('/qr/invites', [QRInviteController::class, 'store'])
            ->middleware('throttle:invite-generate');
        Route::get('/qr/invites', [QRInviteController::class, 'index']);
        Route::post('/qr/invites/{id}/revoke', [QRInviteController::class, 'revoke'])
            ->middleware('throttle:invite-generate');

        /*
        | Attendance — record + read + lookup + context analytics
        */
        Route::get('/attendances/lookup/{qrToken}', [AttendanceController::class, 'lookupByToken']);
        Route::get('/attendances/lookup-member-id/{memberId}', [AttendanceController::class, 'lookupByMemberId']);
        Route::post('/attendances/record', [AttendanceController::class, 'record'])
            ->middleware('throttle:attendance-record');
        Route::post('/attendances/record-by-member-id', [AttendanceController::class, 'recordByMemberId'])
            ->middleware('throttle:attendance-record');
        Route::get('/attendances/today', [AttendanceController::class, 'today'])
            ->middleware('throttle:attendance-read');
        Route::get('/attendances/by-class/{classYearId}', [AttendanceController::class, 'byClass'])
            ->middleware('throttle:attendance-read');
        Route::get('/attendances/context-summary', [AttendanceController::class, 'contextSummary'])
            ->middleware('throttle:attendance-read');
        Route::get('/attendances/context-details', [AttendanceController::class, 'contextDetails'])
            ->middleware('throttle:attendance-read');

        Route::get('/attendances/filtered', [AttendanceController::class, 'filtered'])
            ->middleware('throttle:attendance-read');
        Route::get('/attendances/absent-members', [AttendanceController::class, 'absentMembers'])
            ->middleware('throttle:attendance-read');

        /*
        | User — members list + detail (servants can view their members)
        */
        Route::get('/users/members/{servantId?}', [UserController::class, 'members'])
            ->middleware('throttle:user-list');
        Route::get('/users/member-detail/{id}', [UserController::class, 'show'])
            ->middleware('throttle:user-list');

        /*
        | User — servants list
        */
        Route::get('/users/servants', [UserController::class, 'servantsMe'])
            ->middleware('throttle:user-list');

        /*
        | Feedback — read / resolve / reply / show
        */
        Route::get('/feedback', [FeedbackController::class, 'index'])
            ->middleware('throttle:feedback-read');
        Route::get('/feedback/{id}', [FeedbackController::class, 'show'])
            ->middleware('throttle:feedback-read');
        Route::patch('/feedback/{id}/resolve', [FeedbackController::class, 'resolve'])
            ->middleware('throttle:feedback-read');
        Route::post('/feedback/{id}/reply', [FeedbackController::class, 'reply'])
            ->middleware('throttle:feedback-read');

        /*
        | Attendance Contexts — Management CRUD
        */
        Route::get('/attendance-contexts/manage', [AttendanceContextController::class, 'index'])
            ->middleware('throttle:attendance-context-crud');
        Route::get('/attendance-contexts/{id}', [AttendanceContextController::class, 'show'])
            ->middleware('throttle:attendance-context-crud');
        Route::post('/attendance-contexts', [AttendanceContextController::class, 'store'])
            ->middleware('throttle:attendance-context-crud');
        Route::put('/attendance-contexts/{id}', [AttendanceContextController::class, 'update'])
            ->middleware('throttle:attendance-context-crud');
        Route::patch('/attendance-contexts/{id}/toggle-active', [AttendanceContextController::class, 'toggleActive'])
            ->middleware('throttle:attendance-context-crud');
        Route::delete('/attendance-contexts/{id}', [AttendanceContextController::class, 'destroy'])
            ->middleware('throttle:attendance-context-crud');
    });

        /*
        | My Class Servants — any authenticated user (members need this to see contacts)
        | Placed here before manage_users group to avoid /users/{id} catching it.
        */
        Route::get('/users/my-class-servants', [UserController::class, 'myClassServants'])
            ->middleware('throttle:user-list');

    /*
    |--------------------------------------------------------------------------
    | Admin Routes (permission: manage_users)
    |--------------------------------------------------------------------------
    */

    Route::middleware(['permission:manage_users', 'approved'])->group(function () {

        /*
        | Password Reset Requests — admin review
        */
        Route::get('/password-reset-requests', [PasswordResetRequestController::class, 'index'])
            ->middleware('throttle:api');
        Route::get('/password-reset-requests/{id}', [PasswordResetRequestController::class, 'show'])
            ->middleware('throttle:api');
        Route::post('/password-reset-requests/{id}/approve', [PasswordResetRequestController::class, 'approve'])
            ->middleware('throttle:sensitive');
        Route::post('/password-reset-requests/{id}/reject', [PasswordResetRequestController::class, 'reject'])
            ->middleware('throttle:sensitive');

        /*
        | User management
        */
        Route::get('/users/{id}/servants', [UserController::class, 'servants'])
            ->middleware('throttle:user-list');
        Route::post('/users/{id}/promote', [UserController::class, 'promote'])
            ->middleware('throttle:sensitive');
        Route::post('/users/{id}/demote', [UserController::class, 'demote'])
            ->middleware('throttle:sensitive');

        Route::get('/users', [UserController::class, 'index'])
            ->middleware('throttle:user-list');
        Route::get('/users/{id}', [UserController::class, 'show'])
            ->middleware('throttle:user-list');
        Route::post('/users', [UserController::class, 'store'])
            ->middleware('throttle:user-create');
        Route::put('/users/{id}', [UserController::class, 'update'])
            ->middleware('throttle:user-update');
        Route::patch('/users/{id}', [UserController::class, 'update'])
            ->middleware('throttle:user-update');
        Route::delete('/users/{id}', [UserController::class, 'destroy'])
            ->middleware('throttle:user-delete');

        /*
        | Stages — full CRUD
        */
        Route::post('/stages', [StageController::class, 'store'])
            ->middleware('throttle:structure-crud');
        Route::post('/stages/bulk', [StageController::class, 'bulkCreate'])
            ->middleware('throttle:structure-crud');
        Route::put('/stages/{id}', [StageController::class, 'update'])
            ->middleware('throttle:structure-crud');
        Route::patch('/stages/{id}', [StageController::class, 'update'])
            ->middleware('throttle:structure-crud');
        Route::delete('/stages/{id}', [StageController::class, 'destroy'])
            ->middleware('throttle:structure-crud');

        /*
        | Classes — full CRUD + assignments + order
        */
        Route::post('/classes', [ClasseController::class, 'store'])
            ->middleware('throttle:structure-crud');
        Route::put('/classes/{id}', [ClasseController::class, 'update'])
            ->middleware('throttle:structure-crud');
        Route::patch('/classes/{id}', [ClasseController::class, 'update'])
            ->middleware('throttle:structure-crud');
        Route::delete('/classes/{id}', [ClasseController::class, 'destroy'])
            ->middleware('throttle:structure-crud');

        Route::get('/classes/{id}/detail', [ClasseController::class, 'detail'])
            ->middleware('throttle:structure-read');
        Route::get('/classes/{id}/members', [ClasseController::class, 'members'])
            ->middleware('throttle:structure-read');
        Route::get('/classes/{id}/servants', [ClasseController::class, 'servants'])
            ->middleware('throttle:structure-read');

        Route::post('/classes/{id}/assign-servant', [ClasseController::class, 'assignServant'])
            ->middleware('throttle:structure-crud');
        Route::post('/classes/{id}/remove-servant', [ClasseController::class, 'removeServant'])
            ->middleware('throttle:structure-crud');
        Route::post('/classes/{id}/assign-member', [ClasseController::class, 'assignMember'])
            ->middleware('throttle:structure-crud');
        Route::post('/classes/reorder', [ClasseController::class, 'updateOrder'])
            ->middleware('throttle:structure-crud');

        /*
        | QR token regeneration — sensitive
        */
        Route::post('/users/{id}/regenerate-qr-token', [UserController::class, 'regenerateUserQrToken'])
            ->middleware('throttle:qr-regenerate');

        /*
        | Churches — admin list
        */
        Route::get('/churches', function () {
            $user = request()->user();

            $query = \App\Models\Church::withCount('users');

            if (!$user->isPlatformAdmin()) {
                $query->where('id', $user->church_id);
            }

            return response()->json([
                'data' => $query->get()->map(fn($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'member_count' => $c->users_count,
                    'is_active' => $c->is_active,
                    'created_at' => $c->created_at->toISOString(),
                ]),
            ]);
        })->middleware('throttle:user-list');

    });

    /*
    |--------------------------------------------------------------------------
    | Points — read other user (admin: manage_users, servant: view_points)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['permission:manage_users,view_points', 'approved'])->group(function () {
        Route::get('/points/user/{userId}/balance', [PointController::class, 'userBalance'])
            ->middleware('throttle:points-read');
        Route::get('/points/user/{userId}/history', [PointController::class, 'userHistory'])
            ->middleware('throttle:points-read');
    });

    /*
    |--------------------------------------------------------------------------
    | Bonus Points — admin & servant (outside manage_users so servants can access)
    |--------------------------------------------------------------------------
    */
    Route::post('/points/bonus', [PointController::class, 'addBonusPoints'])
        ->middleware(['permission:manage_users', 'approved', 'throttle:attendance-record']);

        /*
        | Own QR token regeneration — user scoped
        */
        Route::post('/users/regenerate-qr-token', [UserController::class, 'regenerateOwnQrToken'])
            ->middleware('throttle:qr-regenerate');
    });

/*
|--------------------------------------------------------------------------
| Membership Request Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    Route::post('/membership-requests', [MembershipRequestController::class, 'store'])
        ->middleware('throttle:membership-request');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

        Route::middleware(['permission:manage_membership_requests', 'approved'])->group(function () {
            Route::get('/membership-requests', [MembershipRequestController::class, 'index']);
            Route::get('/membership-requests/{id}', [MembershipRequestController::class, 'show']);
            Route::post('/membership-requests/{id}/approve', [MembershipRequestController::class, 'approve'])
                ->middleware('throttle:sensitive');
            Route::post('/membership-requests/{id}/reject', [MembershipRequestController::class, 'reject'])
                ->middleware('throttle:sensitive');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Platform Admin Routes (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->middleware(['auth:sanctum', 'role:' . UserRole::PlatformAdmin->value, 'throttle:api'])->group(function () {

    Route::get('/platform/dashboard', [PlatformController::class, 'dashboard']);

    /*
    | Churches — platform admin listing for deletion
    */
    Route::get('/platform/churches', function () {
        $churches = \App\Models\Church::withTrashed()->withCount('users')->get();

        return response()->json([
            'data' => $churches->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'member_count' => $c->users_count,
                'is_active' => $c->is_active,
                'is_deleted' => $c->trashed(),
                'deleted_at' => $c->deleted_at?->toISOString(),
                'is_recoverable' => $c->trashed() ? $c->isRecoverable() : false,
                'days_until_purge' => $c->trashed() ? $c->daysUntilPurge() : null,
                'recoverable_until' => $c->recoverable_until?->toISOString(),
                'created_at' => $c->created_at->toISOString(),
            ]),
        ]);
    });

    Route::get('/platform/applications', [PlatformController::class, 'applications']);
    Route::get('/platform/applications/{id}', [PlatformController::class, 'showApplication']);
    Route::post('/platform/applications/{id}/approve', [PlatformController::class, 'approve']);
    Route::post('/platform/applications/{id}/reject', [PlatformController::class, 'reject']);

    /*
    | Church Deletion & Decommission — platform admin only
    | Protected by re-authentication, rate limiting, and confirmation
    */
    Route::get('/platform/churches/{id}/deletion-summary', [ChurchDeletionController::class, 'summary'])
        ->middleware('throttle:api');

    Route::post('/platform/churches/{id}/soft-delete', [ChurchDeletionController::class, 'softDelete'])
        ->middleware('throttle:sensitive');

    Route::post('/platform/churches/{id}/restore', [ChurchDeletionController::class, 'restore'])
        ->middleware('throttle:sensitive');

    Route::post('/platform/churches/{id}/hard-delete', [ChurchDeletionController::class, 'hardDelete'])
        ->middleware('throttle:sensitive');

});


