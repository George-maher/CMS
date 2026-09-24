<?php

namespace App\Services;

use App\Contracts\QRInviteRepositoryInterface;
use App\Contracts\QRInviteServiceInterface;
use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Models\Classe;
use App\Models\QRInvite;
use App\Models\Scopes\ChurchScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QRInviteService implements QRInviteServiceInterface
{
    private const INVITE_EXPIRY_HOURS = 4;

    private const TOKEN_LENGTH = 64;

    public function __construct(
        private readonly QRInviteRepositoryInterface $qrInviteRepository,
    ) {}

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function createInvite(array $data, int $creatorId): array
    {
        /** @var string $typeValue */
        $typeValue = $data['type'];
        $type = QRInviteType::from($typeValue);

        /** @var int|null $maxUsesInput */
        $maxUsesInput = $data['max_uses'] ?? null;
        $maxUses = $maxUsesInput !== null ? intval($maxUsesInput) : 1;
        /** @var int|null $expiresInHoursInput */
        $expiresInHoursInput = $data['expires_in_hours'] ?? null;
        $expiresInHours = $expiresInHoursInput !== null ? intval($expiresInHoursInput) : self::INVITE_EXPIRY_HOURS;
        /** @var string|null $contextId */
        $contextId = $data['attendance_context_id'] ?? null;
        $clientRequestInput = $data['client_request_id'] ?? null;
        $clientRequestId = is_string($clientRequestInput) && $clientRequestInput !== ''
            ? $clientRequestInput
            : null;

        /** @var int|null $classId */
        $classId = isset($data['class_id']) && is_numeric($data['class_id']) ? (int) $data['class_id'] : null;
        /** @var int|null $stageId */
        $stageId = isset($data['stage_id']) && is_numeric($data['stage_id']) ? (int) $data['stage_id'] : null;
        $resolvedStageId = $this->resolveInviteStage($classId, $stageId);

        $existingByKey = function () use ($creatorId, $clientRequestId): ?QRInvite {
            if ($clientRequestId === null) {
                return null;
            }

            return QRInvite::where('created_by', $creatorId)
                ->where('client_request_id', $clientRequestId)
                ->first();
        };

        // Fast path: an invite for this (creator, request key) already exists.
        $existing = $existingByKey();
        if ($existing) {
            return [
                'invite' => $existing,
                'url' => $this->getInviteUrl($existing->token),
                'token' => $existing->token,
            ];
        }

        try {
            return DB::transaction(function () use ($type, $creatorId, $contextId, $expiresInHours, $maxUses, $clientRequestId, $classId, $resolvedStageId, $existingByKey) {
                $existing = $existingByKey();
                if ($existing) {
                    return [
                        'invite' => $existing,
                        'url' => $this->getInviteUrl($existing->token),
                        'token' => $existing->token,
                    ];
                }

                $token = Str::random(self::TOKEN_LENGTH);

                $invite = $this->qrInviteRepository->create([
                    'type' => $type,
                    'token' => $token,
                    'client_request_id' => $clientRequestId,
                    'created_by' => $creatorId,
                    'class_id' => $classId,
                    'stage_id' => $resolvedStageId,
                    'attendance_context_id' => $contextId,
                    'expires_at' => now()->addHours($expiresInHours),
                    'is_single_use' => $maxUses === 1,
                    'max_uses' => $maxUses,
                    'use_count' => 0,
                ]);

                Log::info('Invite created', [
                    'invite_id' => $invite->id,
                    'type' => $type->value,
                    'created_by' => $creatorId,
                    'stage_id' => $resolvedStageId,
                    'expires_at' => $invite->expires_at,
                ]);

                return [
                    'invite' => $invite,
                    'url' => $this->getInviteUrl($token),
                    'token' => $token,
                ];
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request already inserted the row for this
            // (created_by, client_request_id) key. Reuse it instead of
            // producing a duplicate invite.
            $existing = $existingByKey();
            if ($existing) {
                return [
                    'invite' => $existing,
                    'url' => $this->getInviteUrl($existing->token),
                    'token' => $existing->token,
                ];
            }

            throw $e;
        }
    }

    /**
     * Resolve the stage an invitation is scoped to, preferring the stage of the
     * targeted class and falling back to an explicitly provided stage. A class
     * that falls outside the resolved stage (or another church) is rejected so
     * invitations can never leak across stage boundaries.
     */
    private function resolveInviteStage(?int $classId, ?int $stageId): ?int
    {
        if ($classId === null) {
            return $stageId;
        }

        $classe = Classe::query()->where('id', $classId)->first();
        if ($classe === null) {
            throw ValidationException::withMessages([
                'class_id' => [__('invite.class_not_found')],
            ]);
        }

        $classStageId = $classe->stage_id !== null ? (int) $classe->stage_id : null;
        if ($stageId !== null && $classStageId !== null && $classStageId !== $stageId) {
            throw ValidationException::withMessages([
                'stage_id' => [__('invite.class_stage_mismatch')],
            ]);
        }

        return $classStageId ?? $stageId;
    }

    /** @return array<string, mixed> */
    public function validateToken(string $token): array
    {
        $invite = $this->qrInviteRepository->findByToken($token);

        if (! $invite) {
            throw ValidationException::withMessages([
                'token' => [__('invite.invalid_token')],
            ]);
        }

        if (! $invite->isValid()) {
            if ($invite->isExpired()) {
                throw ValidationException::withMessages([
                    'token' => [__('invite.expired')],
                ]);
            }
            if ($invite->is_revoked) {
                throw ValidationException::withMessages([
                    'token' => [__('invite.revoked')],
                ]);
            }
            if ($invite->max_uses !== null && $invite->use_count >= $invite->max_uses) {
                throw ValidationException::withMessages([
                    'token' => [__('invite.max_uses_reached')],
                ]);
            }
            if ($invite->isUsed()) {
                throw ValidationException::withMessages([
                    'token' => [__('invite.already_used')],
                ]);
            }
            throw ValidationException::withMessages([
                'token' => [__('invite.not_found')],
            ]);
        }

        return [
            'valid' => true,
            'invite' => $invite,
            'type' => $invite->type,
        ];
    }

    /** @return array<string, mixed> */
    public function validateTokenForRegistration(string $token): array
    {
        /** @var array{valid: bool, invite: QRInvite, type: QRInviteType} $validation */
        $validation = $this->validateToken($token);
        /** @var QRInvite $invite */
        $invite = $validation['invite'];

        $role = $invite->type->targetRole();
        if (! $role) {
            throw ValidationException::withMessages([
                'token' => [__('invite.role_mismatch')],
            ]);
        }

        return [
            'valid' => true,
            'invite' => $invite,
            'type' => $invite->type,
            'role' => $role,
        ];
    }

    /** @return array<string, mixed> */
    public function getInviteDetails(string $token): array
    {
        $invite = $this->qrInviteRepository->findByToken($token);

        if (! $invite) {
            throw ValidationException::withMessages([
                'token' => [__('invite.not_found')],
            ]);
        }

        // Public endpoint: the invite is authorized by its token, so relation and
        // class loads run unscoped but stay bound to the invite's own FKs/church.
        $unscope = static fn (Relation $query) => $query->withoutGlobalScope(ChurchScope::class);
        $invite->load([
            'creator.classe' => $unscope,
            'creator.classe.stage' => $unscope,
            'classe' => $unscope,
            'classe.stage' => $unscope,
            'stage' => $unscope,
        ]);
        $classesQuery = Classe::withoutGlobalScope(ChurchScope::class)
            ->where('church_id', $invite->church_id)
            ->select(['id', 'name', 'stage_id']);
        if ($invite->stage_id !== null) {
            // Only classes inside the invitation's stage are eligible.
            $classesQuery->where('stage_id', $invite->stage_id);
        }
        $classes = $classesQuery->orderBy('name')->get();

        $targetRole = $invite->type->targetRole();

        return [
            'valid' => $invite->isValid(),
            'invite' => $invite,
            'type' => $invite->type,
            'type_label' => $invite->type->label(),
            'role' => $targetRole,
            'role_label' => $targetRole?->label(),
            'creator_name' => $invite->creator?->name,
            'creator_class_id' => $invite->creator?->classe?->id,
            'creator_class_name' => $invite->creator?->classe?->name,
            'class_id' => $invite->class_id,
            'class_name' => $invite->classe?->name,
            'stage_id' => $invite->stage_id,
            'stage_name' => $invite->stage?->name,
            'classes' => $classes->toArray(),
            'expires_at' => $invite->expires_at,
            'is_expired' => $invite->isExpired(),
            'is_used' => $invite->isUsed(),
            'is_revoked' => $invite->is_revoked,
        ];
    }

    /** @return array<string, mixed> */
    public function acceptInvite(string $token, int $userId, ?int $classId = null): array
    {
        /** @var array{valid: bool, invite: QRInvite, type: QRInviteType} $validation */
        $validation = $this->validateToken($token);

        /** @var QRInvite $invite */
        $invite = $validation['invite'];
        $role = $invite->type->targetRole();

        if (! $role) {
            throw ValidationException::withMessages([
                'invite' => [__('invite.role_mismatch')],
            ]);
        }

        return DB::transaction(function () use ($invite, $role, $userId, $classId) {
            // Unscoped lock fetch (invite already authorized by token); the
            // explicit church binding below is the cross-tenant guard.
            $freshInvite = QRInvite::withoutGlobalScope(ChurchScope::class)
                ->where('id', $invite->id)
                ->lockForUpdate()
                ->first();

            if (! $freshInvite || ! $freshInvite->isValid()) {
                $msg = $freshInvite && $freshInvite->max_uses !== null && $freshInvite->use_count >= $freshInvite->max_uses
                    ? __('invite.max_uses_reached')
                    : __('invite.already_used');
                throw ValidationException::withMessages([
                    'invite' => [$msg],
                ]);
            }

            $user = User::find($userId);
            if (! $user) {
                throw ValidationException::withMessages([
                    'user' => ['User not found.'],
                ]);
            }

            // Cross-tenant guard: an invite may only be accepted by a user of the
            // same church. The lock fetch above intentionally runs unscoped (the
            // invite is token-authorized), so this explicit binding is the
            // security boundary — fail-closed on any church mismatch.
            if ((int) $freshInvite->church_id !== (int) $user->church_id) {
                throw ValidationException::withMessages([
                    'invite' => [__('invite.church_mismatch')],
                ]);
            }

            if ($user->role->value === $role->value) {
                throw ValidationException::withMessages([
                    'invite' => ['You are already registered as a '.$role->label().'.'],
                ]);
            }

            $updateData = [
                'role' => $role->value,
                'invite_id' => $freshInvite->id,
            ];

            $resolvedStageId = $freshInvite->stage_id !== null ? (int) $freshInvite->stage_id : null;

            // Class is chosen by the user during accept, not carried by the invite.
            // It must belong to the invitation's stage (and church) — otherwise an
            // invite could place a user into a stage the inviter does not manage.
            if ($classId) {
                $classe = Classe::query()
                    ->where('id', $classId)
                    ->where('church_id', $freshInvite->church_id)
                    ->first();
                if (! $classe) {
                    throw ValidationException::withMessages([
                        'class_id' => [__('invite.class_not_found')],
                    ]);
                }
                $classStageId = $classe->stage_id !== null ? (int) $classe->stage_id : null;
                if ($freshInvite->stage_id !== null && $classStageId !== null && $classStageId !== (int) $freshInvite->stage_id) {
                    throw ValidationException::withMessages([
                        'class_id' => [__('invite.class_stage_mismatch')],
                    ]);
                }
                $updateData['class_id'] = $classId;
                $resolvedStageId ??= $classStageId;
            }

            $updateData['stage_id'] = $resolvedStageId;
            $updateData['scope'] = $role === UserRole::Member ? UserScope::Self->value : UserScope::ClassScope->value;

            if ($role === UserRole::Member) {
                $updateData['servant_id'] = $freshInvite->created_by;
            }

            $user->update($updateData);

            $used = $freshInvite->markAsUsed($userId);
            if (! $used) {
                throw ValidationException::withMessages([
                    'invite' => [__('invite.max_uses_reached')],
                ]);
            }

            $user->tokens()->delete();

            Log::info('Invite accepted — tokens revoked, re-login required', [
                'invite_id' => $freshInvite->id,
                'user_id' => $userId,
                'role' => $role->value,
            ]);

            $freshUser = $user->fresh();
            if (! $freshUser) {
                throw ValidationException::withMessages([
                    'user' => ['User not found after update.'],
                ]);
            }

            return [
                'user' => $freshUser->load(['classe', 'servant']),
                'role' => $role,
                'message' => 'Role updated. Please log in again with your new permissions.',
            ];
        });
    }

    public function findById(int $id): ?QRInvite
    {
        return $this->qrInviteRepository->findById($id);
    }

    public function revokeInvite(int $id): bool
    {
        return $this->qrInviteRepository->revoke($id);
    }

    public function rotateInvite(int $id): QRInvite
    {
        return DB::transaction(function () use ($id): QRInvite {
            /** @var QRInvite|null $invite */
            $invite = QRInvite::query()->whereKey($id)->lockForUpdate()->first();
            if (! $invite) {
                throw ValidationException::withMessages([
                    'invite' => [__('invite.not_found')],
                ]);
            }

            $invite->update([
                'token' => Str::random(self::TOKEN_LENGTH),
                'expires_at' => now()->addHours(self::INVITE_EXPIRY_HOURS),
                'is_revoked' => false,
            ]);

            /** @var QRInvite $rotated */
            $rotated = $invite->fresh();
            if (! $rotated) {
                throw ValidationException::withMessages([
                    'invite' => ['The invite could not be rotated.'],
                ]);
            }

            Log::info('Invite token rotated', [
                'invite_id' => $rotated->id,
                'expires_at' => $rotated->expires_at,
            ]);

            return $rotated;
        });
    }

    public function getInviteUrl(string $token): string
    {
        /** @var string $frontendUrl */
        $frontendUrl = config('app.frontend_url');

        return $frontendUrl.'/invite/'.urlencode($token);
    }

    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    public function listInvites(int $perPage = 15, array $filters = []): array
    {
        $paginator = $this->qrInviteRepository->paginate($perPage, $filters);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
