<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\ChurchDeletionException;
use App\Models\Attendance;
use App\Models\AttendanceContext;
use App\Models\AuditLog;
use App\Models\Church;
use App\Models\Classe;
use App\Models\DailySpiritualRecord;
use App\Models\DailyVerse;
use App\Models\Event;
use App\Models\EventAccommodation;
use App\Models\EventBus;
use App\Models\EventBusSheet;
use App\Models\EventPayment;
use App\Models\EventRegistration;
use App\Models\EventRoom;
use App\Models\EventRoomCell;
use App\Models\EventSession;
use App\Models\EventSpeaker;
use App\Models\EventTarget;
use App\Models\EventView;
use App\Models\Feedback;
use App\Models\FeedbackReply;
use App\Models\MembershipRequest;
use App\Models\Notification;
use App\Models\PasswordResetRequest;
use App\Models\Point;
use App\Models\ProfileUpdateRequest;
use App\Models\QRInvite;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChurchDeletionService
{
    private const RECOVERY_DAYS = 30;

    public function __construct(
        private readonly AuditService $auditService,
        private readonly CacheService $cacheService,
    ) {}

    /**
     * Build a fault-tolerant deletion summary.
     *
     * Every count is wrapped so a single missing/unexpected table (e.g. a
     * migration not yet applied on the target environment) can never kill the
     * confirmation preview nor the delete transaction. Failures are logged
     * server-side and flagged via `schema_warnings` for the UI.
     *
     * @return array<string, mixed>
     */
    public function getDeletionSummary(Church $church): array
    {
        $userIds = User::where('church_id', $church->id)->pluck('id');
        $feedbackIds = Feedback::where('church_id', $church->id)->pluck('id');
        $eventIds = Event::where('church_id', $church->id)->pluck('id');
        $registrationIds = EventRegistration::query()
            ->where(function (Builder $q) use ($userIds, $eventIds) {
                $q->whereIn('user_id', $userIds)->orWhereIn('event_id', $eventIds);
            })
            ->pluck('id');
        $roomIds = EventRoom::whereIn('event_id', $eventIds)->pluck('id');
        $cellIds = EventRoomCell::whereIn('room_id', $roomIds)->pluck('id');
        $busIds = EventBus::whereIn('event_id', $eventIds)->pluck('id');

        $warnings = false;

        $count = function (\Closure $fn, string $label) use (&$warnings): int {
            try {
                return $this->countForSummary($fn);
            } catch (Throwable $e) {
                $warnings = true;
                Log::warning("Church deletion summary: failed to count {$label}", [
                    'error' => $e->getMessage(),
                ]);

                return 0;
            }
        };

        $counts = [
            'church_id' => $church->id,
            'church_name' => $church->name,
            'total_users' => $userIds->count(),
            'total_members' => $count(fn () => User::where('church_id', $church->id)->where('role', UserRole::Member)->count(), 'total_members'),
            'total_servants' => $count(fn () => User::where('church_id', $church->id)->where('role', UserRole::Servant)->count(), 'total_servants'),
            'total_admins' => $count(fn () => User::where('church_id', $church->id)->whereIn('role', [UserRole::Admin, UserRole::AssistantAdmin])->count(), 'total_admins'),
            'total_events' => $eventIds->count(),
            'total_event_sessions' => $count(fn () => EventSession::whereIn('event_id', $eventIds)->count(), 'total_event_sessions'),
            'total_event_speakers' => $count(fn () => EventSpeaker::whereIn('event_id', $eventIds)->count(), 'total_event_speakers'),
            'total_event_buses' => $count(fn () => EventBus::whereIn('event_id', $eventIds)->count(), 'total_event_buses'),
            'total_event_rooms' => $count(fn () => EventRoom::whereIn('event_id', $eventIds)->count(), 'total_event_rooms'),
            'total_event_room_cells' => $count(fn () => EventRoomCell::whereIn('room_id', $roomIds)->count(), 'total_event_room_cells'),
            'total_event_registrations' => $registrationIds->count(),
            'total_event_payments' => $count(fn () => EventPayment::whereIn('registration_id', $registrationIds)->count(), 'total_event_payments'),
            'total_event_accommodations' => $count(function () use ($registrationIds, $cellIds) {
                return EventAccommodation::whereIn('registration_id', $registrationIds)
                    ->orWhereIn('cell_id', $cellIds)
                    ->count();
            }, 'total_event_accommodations'),
            'total_event_bus_sheets' => $count(function () use ($registrationIds, $busIds) {
                return EventBusSheet::whereIn('registration_id', $registrationIds)
                    ->orWhereIn('bus_id', $busIds)
                    ->count();
            }, 'total_event_bus_sheets'),
            'total_attendances' => $count(fn () => Attendance::where('church_id', $church->id)->count(), 'total_attendances'),
            'total_attendance_contexts' => $count(fn () => AttendanceContext::where('church_id', $church->id)->count(), 'total_attendance_contexts'),
            'total_qr_invites' => $count(fn () => QRInvite::where('church_id', $church->id)->count(), 'total_qr_invites'),
            'total_points' => $count(fn () => Point::where('church_id', $church->id)->count(), 'total_points'),
            'total_feedback' => $feedbackIds->count(),
            'total_feedback_replies' => $count(fn () => FeedbackReply::whereIn('feedback_id', $feedbackIds)->count(), 'total_feedback_replies'),
            'total_event_views' => $count(fn () => EventView::where('church_id', $church->id)->count(), 'total_event_views'),
            'total_event_targets' => $count(fn () => EventTarget::where('church_id', $church->id)->count(), 'total_event_targets'),
            'total_notifications' => $count(fn () => Notification::where('church_id', $church->id)->count(), 'total_notifications'),
            'total_daily_verses' => $count(fn () => DailyVerse::where('church_id', $church->id)->count(), 'total_daily_verses'),
            'total_membership_requests' => $count(fn () => MembershipRequest::where('church_id', $church->id)->count(), 'total_membership_requests'),
            'total_stages' => $count(fn () => Stage::where('church_id', $church->id)->count(), 'total_stages'),
            'total_classes' => $count(fn () => Classe::where('church_id', $church->id)->count(), 'total_classes'),
            'total_profile_update_requests' => $count(fn () => ProfileUpdateRequest::whereIn('user_id', $userIds)->count(), 'total_profile_update_requests'),
            'total_daily_spiritual_records' => $count(fn () => DailySpiritualRecord::where('church_id', $church->id)->count(), 'total_daily_spiritual_records'),
            'total_class_years' => $count(fn () => DB::table('class_years')->where('church_id', $church->id)->count(), 'total_class_years'),
            'total_password_reset_requests' => $count(fn () => PasswordResetRequest::whereIn('user_id', $userIds)->count(), 'total_password_reset_requests'),
            'total_audit_logs' => $count(fn () => AuditLog::where('church_id', $church->id)->count(), 'total_audit_logs'),
            'schema_warnings' => $warnings,
        ];

        $counts['total_records'] = array_sum(array_diff_key($counts, array_flip(['church_id', 'church_name', 'schema_warnings'])));

        return $counts;
    }

    /**
     * Execute a single summary count, guaranteeing an int return even if the
     * underlying query throws. The caller wraps this in the $warnings handling.
     *
     * @param  \Closure(): int  $fn
     */
    private function countForSummary(\Closure $fn): int
    {
        return $fn();
    }

    public function softDelete(Church $church, User $admin): Church
    {
        if ($church->trashed()) {
            throw new ChurchDeletionException('ALREADY_DELETED', __('church_deletion.already_deleted'), 409);
        }

        $summary = $this->getDeletionSummary($church);

        try {
            $result = DB::transaction(function () use ($church, $admin, $summary) {
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

                /** @var string|null $recoverableUntilIso */
                $recoverableUntilIso = $church->recoverable_until?->toISOString();

                $this->auditService->log(
                    action: 'church_soft_deleted',
                    resourceType: 'church',
                    resourceId: $church->id,
                    oldValues: ['name' => $church->name, 'is_active' => true],
                    newValues: [
                        'deleted_at' => now()->toISOString(),
                        'deleted_by' => $admin->name,
                        'deletion_type' => 'soft',
                        'recoverable_until' => $recoverableUntilIso,
                        'affected_users' => $summary['total_users'],
                        'total_records' => $summary['total_records'],
                    ],
                    userId: $admin->id,
                );

                return $church->fresh() ?? $church;
            });

            $this->invalidateChurchCaches($church);

            return $result;
        } catch (ChurchDeletionException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Church soft delete failed', [
                'church_id' => $church->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            throw new ChurchDeletionException('CHURCH_DELETE_FAILED', __('church_deletion.delete_failed'), 500, $e);
        }
    }

    public function restore(Church $church, User $admin): Church
    {
        if (! $church->trashed()) {
            throw new ChurchDeletionException('NOT_DELETED', __('church_deletion.not_deleted'), 409);
        }

        if (! $church->isRecoverable()) {
            throw new ChurchDeletionException('RECOVERY_WINDOW_EXPIRED', __('church_deletion.recovery_window_expired'), 422);
        }

        try {
            $result = DB::transaction(function () use ($church, $admin) {
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

                return $church->fresh() ?? $church;
            });

            $this->invalidateChurchCaches($church);

            return $result;
        } catch (ChurchDeletionException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Church restore failed', [
                'church_id' => $church->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            throw new ChurchDeletionException('CHURCH_DELETE_FAILED', __('church_deletion.delete_failed'), 500, $e);
        }
    }

    /** @return LengthAwarePaginator<int, Church> */
    public function getDeletedChurches(
        ?string $search = null,
        ?string $churchName = null,
        ?string $priestName = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $perPage = min($perPage, 100);
        $query = Church::onlyTrashed()
            ->with(['deletedBy' => function ($q) {
                /** @var BelongsTo<User, Church> $q */
                $q->select('id', 'name', 'email');
            }])
            ->withCount('users');

        if ($search) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('priest_name', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($churchName) {
            $query->where('name', 'like', "%{$churchName}%");
        }

        if ($priestName) {
            $query->where('priest_name', 'like', "%{$priestName}%");
        }

        if ($dateFrom) {
            $query->whereDate('deleted_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('deleted_at', '<=', $dateTo);
        }

        return $query->orderBy('deleted_at', 'desc')->paginate($perPage);
    }

    public function getDeletedChurchDetail(int $id): Church
    {
        $church = Church::onlyTrashed()
            ->with([
                'deletedBy' => function ($q) {
                    /** @var BelongsTo<User, Church> $q */
                    $q->select('id', 'name', 'email');
                },
                'users' => function ($q) {
                    /** @var HasMany<User, Church> $q */
                    $q->select('id', 'name', 'email', 'role', 'is_active');
                },
            ])
            ->findOrFail($id);

        return $church;
    }

    public function hardDelete(Church $church, User $admin): void
    {
        $summary = $this->getDeletionSummary($church);

        try {
            DB::transaction(function () use ($church, $admin, $summary) {
                $userIds = User::where('church_id', $church->id)->pluck('id');
                $classIds = Classe::where('church_id', $church->id)->pluck('id');
                $feedbackIds = Feedback::where('church_id', $church->id)->pluck('id');
                $eventIds = Event::where('church_id', $church->id)->pluck('id');
                $registrationIds = EventRegistration::query()
                    ->where(function (Builder $q) use ($userIds, $eventIds) {
                        $q->whereIn('user_id', $userIds)->orWhereIn('event_id', $eventIds);
                    })
                    ->pluck('id');
                $roomIds = EventRoom::whereIn('event_id', $eventIds)->pluck('id');
                $cellIds = EventRoomCell::whereIn('room_id', $roomIds)->pluck('id');
                $busIds = EventBus::whereIn('event_id', $eventIds)->pluck('id');

                DB::table('personal_access_tokens')
                    ->whereIn('tokenable_id', $userIds)
                    ->where('tokenable_type', (new User)->getMorphClass())
                    ->delete();

                // --- Event management subtree ---------------------------------
                // event_registrations.user_id is a RESTRICT FK. Cross-church rows
                // (this church's users registered on another church's event) are
                // NOT reachable through the church's own events, so they must be
                // removed explicitly BEFORE the users are force-deleted.
                EventAccommodation::whereIn('registration_id', $registrationIds)
                    ->orWhereIn('cell_id', $cellIds)
                    ->delete();
                EventBusSheet::whereIn('registration_id', $registrationIds)
                    ->orWhereIn('bus_id', $busIds)
                    ->delete();
                EventPayment::whereIn('registration_id', $registrationIds)->delete();
                EventRegistration::whereIn('id', $registrationIds)->delete();

                EventRoomCell::whereIn('room_id', $roomIds)->delete();
                EventRoom::whereIn('event_id', $eventIds)->delete();
                EventSession::whereIn('event_id', $eventIds)->delete();
                EventSpeaker::whereIn('event_id', $eventIds)->delete();
                EventBus::whereIn('event_id', $eventIds)->delete();

                // --- Core tables ----------------------------------------------
                PasswordResetRequest::whereIn('user_id', $userIds)->delete();
                ProfileUpdateRequest::whereIn('user_id', $userIds)->delete();
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
                DailySpiritualRecord::where('church_id', $church->id)->delete();
                Event::where('church_id', $church->id)->delete();
                MembershipRequest::where('church_id', $church->id)->delete();
                DB::table('class_servant')->whereIn('class_id', $classIds)->delete();
                Classe::where('church_id', $church->id)->delete();
                Stage::where('church_id', $church->id)->delete();
                DB::table('class_years')->where('church_id', $church->id)->delete();
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

            $this->invalidateChurchCaches($church);
        } catch (ChurchDeletionException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Church hard delete failed', [
                'church_id' => $church->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            throw new ChurchDeletionException('CHURCH_DELETE_FAILED', __('church_deletion.delete_failed'), 500, $e);
        }
    }

    /**
     * Invalidate every per-church namespace plus the per-user auth/unread
     * caches of the church's users. Safe to call after soft-delete (users
     * deactivated), restore (users reactivated) and hard-delete (data gone).
     */
    private function invalidateChurchCaches(Church $church): void
    {
        $this->cacheService->invalidateAllChurch($church->id);
        $this->cacheService->invalidateDashboard($church->id);

        /** @var Collection<int, int> $userIds */
        $userIds = User::withTrashed()->where('church_id', $church->id)->pluck('id');
        foreach ($userIds as $userId) {
            $this->cacheService->invalidateUserAuth((int) $userId);
            $this->cacheService->invalidateUnreadCount((int) $userId);
        }
    }
}
