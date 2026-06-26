<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceContext;
use App\Models\AuditLog;
use App\Models\Church;
use App\Models\Classe;
use App\Models\DailyVerse;
use App\Models\Event;
use App\Models\EventTarget;
use App\Models\EventView;
use App\Models\Feedback;
use App\Models\FeedbackReply;
use App\Models\MembershipRequest;
use App\Models\Notification;
use App\Models\PasswordResetRequest;
use App\Models\Point;
use App\Models\QRInvite;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChurchDeletionService
{
    private const RECOVERY_DAYS = 30;

    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function getDeletionSummary(Church $church): array
    {
        $userIds = User::where('church_id', $church->id)->pluck('id');
        $feedbackIds = Feedback::where('church_id', $church->id)->pluck('id');

        $counts = [
            'church_id' => $church->id,
            'church_name' => $church->name,
            'total_users' => $userIds->count(),
            'total_members' => User::where('church_id', $church->id)->where('role', UserRole::Member)->count(),
            'total_servants' => User::where('church_id', $church->id)->where('role', UserRole::Servant)->count(),
            'total_admins' => User::where('church_id', $church->id)->whereIn('role', [UserRole::Admin, UserRole::AssistantAdmin])->count(),
            'total_events' => Event::where('church_id', $church->id)->count(),
            'total_attendances' => Attendance::where('church_id', $church->id)->count(),
            'total_attendance_contexts' => AttendanceContext::where('church_id', $church->id)->count(),
            'total_qr_invites' => QRInvite::where('church_id', $church->id)->count(),
            'total_points' => Point::where('church_id', $church->id)->count(),
            'total_feedback' => $feedbackIds->count(),
            'total_feedback_replies' => FeedbackReply::whereIn('feedback_id', $feedbackIds)->count(),
            'total_event_views' => EventView::where('church_id', $church->id)->count(),
            'total_event_targets' => EventTarget::where('church_id', $church->id)->count(),
            'total_notifications' => Notification::where('church_id', $church->id)->count(),
            'total_daily_verses' => DailyVerse::where('church_id', $church->id)->count(),
            'total_membership_requests' => MembershipRequest::where('church_id', $church->id)->count(),
            'total_stages' => Stage::where('church_id', $church->id)->count(),
            'total_classes' => Classe::where('church_id', $church->id)->count(),
            'total_password_reset_requests' => PasswordResetRequest::whereIn('user_id', $userIds)->count(),
            'total_audit_logs' => AuditLog::where('church_id', $church->id)->count(),
        ];

        $counts['total_records'] = array_sum(array_diff_key($counts, array_flip(['church_id', 'church_name'])));

        return $counts;
    }

    public function softDelete(Church $church, User $admin): Church
    {
        if ($church->trashed()) {
            abort(422, __('church_deletion.already_deleted'));
        }

        return DB::transaction(function () use ($church, $admin) {
            $summary = $this->getDeletionSummary($church);

            $userIds = User::where('church_id', $church->id)->pluck('id');

            DB::table('personal_access_tokens')
                ->whereIn('tokenable_id', $userIds)
                ->where('tokenable_type', (new User)->getMorphClass())
                ->delete();

            User::where('church_id', $church->id)->update(['is_active' => false]);

            $church->update([
                'deleted_by' => $admin->id,
                'deletion_type' => 'soft',
                'recoverable_until' => now()->addDays(self::RECOVERY_DAYS),
            ]);

            $church->delete();

            $this->auditService->log(
                action: 'church_soft_deleted',
                resourceType: 'church',
                resourceId: $church->id,
                oldValues: ['name' => $church->name, 'is_active' => true],
                newValues: [
                    'deleted_at' => now()->toISOString(),
                    'deleted_by' => $admin->name,
                    'deletion_type' => 'soft',
                    'recoverable_until' => $church->recoverable_until->toISOString(),
                    'affected_users' => $summary['total_users'],
                    'total_records' => $summary['total_records'],
                ],
                userId: $admin->id,
            );

            return $church->fresh() ?? $church;
        });
    }

    public function restore(Church $church, User $admin): Church
    {
        if (!$church->trashed()) {
            abort(422, __('church_deletion.not_deleted'));
        }

        if (!$church->isRecoverable()) {
            abort(422, __('church_deletion.recovery_window_expired'));
        }

        return DB::transaction(function () use ($church, $admin) {
            $church->restore();

            $church->update([
                'is_active' => true,
                'deleted_by' => null,
                'deletion_type' => null,
                'recoverable_until' => null,
            ]);

            User::where('church_id', $church->id)->update(['is_active' => true]);

            $this->auditService->log(
                action: 'church_restored',
                resourceType: 'church',
                resourceId: $church->id,
                oldValues: ['deleted_at' => $church->deleted_at?->toISOString()],
                newValues: [
                    'restored_at' => now()->toISOString(),
                    'restored_by' => $admin->name,
                ],
                userId: $admin->id,
            );

            return $church->fresh();
        });
    }

    public function hardDelete(Church $church, User $admin): void
    {
        DB::transaction(function () use ($church, $admin) {
            $summary = $this->getDeletionSummary($church);

            $userIds = User::where('church_id', $church->id)->pluck('id');
            $classIds = Classe::where('church_id', $church->id)->pluck('id');
            $feedbackIds = Feedback::where('church_id', $church->id)->pluck('id');

            DB::table('personal_access_tokens')
                ->whereIn('tokenable_id', $userIds)
                ->where('tokenable_type', (new User)->getMorphClass())
                ->delete();

            PasswordResetRequest::whereIn('user_id', $userIds)->delete();
            FeedbackReply::whereIn('feedback_id', $feedbackIds)->delete();
            EventView::where('church_id', $church->id)->delete();
            EventTarget::where('church_id', $church->id)->delete();
            Notification::where('church_id', $church->id)->delete();
            Point::where('church_id', $church->id)->delete();
            Attendance::where('church_id', $church->id)->delete();
            QRInvite::where('church_id', $church->id)->delete();
            Feedback::where('church_id', $church->id)->delete();
            DailyVerse::where('church_id', $church->id)->delete();
            AttendanceContext::where('church_id', $church->id)->delete();
            Event::where('church_id', $church->id)->delete();
            MembershipRequest::where('church_id', $church->id)->delete();
            DB::table('class_servant')->whereIn('class_id', $classIds)->delete();
            Classe::where('church_id', $church->id)->delete();
            Stage::where('church_id', $church->id)->delete();
            User::where('church_id', $church->id)->forceDelete();
            AuditLog::where('church_id', $church->id)->delete();

            $churchName = $church->name;
            $churchId = $church->id;
            $church->forceDelete();

            DB::table('audit_logs')->insert([
                'church_id' => null,
                'user_id' => $admin->id,
                'action' => 'church_hard_deleted',
                'resource_type' => 'church',
                'resource_id' => $churchId,
                'old_values' => json_encode(['name' => $churchName, 'is_active' => true]),
                'new_values' => json_encode([
                    'deleted_at' => now()->toISOString(),
                    'deleted_by' => $admin->name,
                    'deletion_type' => 'hard',
                    'affected_users' => $summary['total_users'],
                    'total_records' => $summary['total_records'],
                ]),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
