<?php

namespace App\Services;

use App\Contracts\QRInviteRepositoryInterface;
use App\Contracts\QRInviteServiceInterface;
use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Models\Classe;
use App\Models\QRInvite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QRInviteService implements QRInviteServiceInterface
{
    private const INVITE_EXPIRY_HOURS = 24;
    private const TOKEN_LENGTH = 64;

    public function __construct(
        private readonly QRInviteRepositoryInterface $qrInviteRepository,
    ) {}

    public function createInvite(array $data, int $creatorId): array
    {
        $type = QRInviteType::from($data['type']);

        do {
            $token = Str::random(self::TOKEN_LENGTH);
        } while (QRInvite::where('token', $token)->exists());

        $maxUses = isset($data['max_uses']) ? (int) $data['max_uses'] : 1;
        $expiresInHours = isset($data['expires_in_hours']) ? (int) $data['expires_in_hours'] : self::INVITE_EXPIRY_HOURS;

        $invite = $this->qrInviteRepository->create([
            'type' => $type,
            'token' => $token,
            'created_by' => $creatorId,
            'attendance_context_id' => $data['attendance_context_id'] ?? null,
            'expires_at' => now()->addHours($expiresInHours),
            'is_single_use' => $maxUses === 1,
            'max_uses' => $maxUses,
            'use_count' => 0,
        ]);

        Log::info('Invite created', [
            'invite_id' => $invite->id,
            'type' => $type->value,
            'created_by' => $creatorId,
            'expires_at' => $invite->expires_at,
        ]);

        return [
            'invite' => $invite,
            'url' => $this->getInviteUrl($token),
            'token' => $token,
        ];
    }

    public function validateToken(string $token): array
    {
        $invite = $this->qrInviteRepository->findByToken($token);

        if (!$invite) {
            throw ValidationException::withMessages([
                'token' => ['Invalid QR token.'],
            ]);
        }

        if (!$invite->isValid()) {
            if ($invite->isExpired()) {
                throw ValidationException::withMessages([
                    'token' => ['This QR code has expired.'],
                ]);
            }
            if ($invite->is_revoked) {
                throw ValidationException::withMessages([
                    'token' => ['This QR code has been revoked.'],
                ]);
            }
            if ($invite->max_uses !== null && $invite->use_count >= $invite->max_uses) {
                throw ValidationException::withMessages([
                    'token' => [__('invite.max_uses_reached')],
                ]);
            }
            if ($invite->isUsed()) {
                throw ValidationException::withMessages([
                    'token' => ['This QR code has already been used.'],
                ]);
            }
            throw ValidationException::withMessages([
                'token' => ['This QR code is no longer valid.'],
            ]);
        }

        return [
            'valid' => true,
            'invite' => $invite,
            'type' => $invite->type,
        ];
    }

    public function validateTokenForRegistration(string $token): array
    {
        $validation = $this->validateToken($token);
        $invite = $validation['invite'];

        $role = $invite->type->targetRole();
        if (!$role) {
            throw ValidationException::withMessages([
                'token' => ['This QR code cannot be used for registration.'],
            ]);
        }

        return [
            'valid' => true,
            'invite' => $invite,
            'type' => $invite->type,
            'role' => $role,
        ];
    }

    public function getInviteDetails(string $token): array
    {
        $invite = $this->qrInviteRepository->findByToken($token);

        if (!$invite) {
            throw ValidationException::withMessages([
                'token' => ['Invalid invite token.'],
            ]);
        }

        $invite->load(['creator.classe.stage', 'classe.stage']);
        $classes = Classe::byChurch()
            ->get(['id', 'name']);

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
            'stage_name' => $invite->classe?->stage?->name,
            'classes' => $classes->toArray(),
            'expires_at' => $invite->expires_at,
            'is_expired' => $invite->isExpired(),
            'is_used' => $invite->isUsed(),
            'is_revoked' => $invite->is_revoked,
        ];
    }

    public function acceptInvite(string $token, int $userId, ?int $classId = null): array
    {
        $validation = $this->validateToken($token);
        $invite = $validation['invite'];
        $role = $invite->type->targetRole();

        if (!$role) {
            throw ValidationException::withMessages([
                'invite' => ['This invite type cannot be used for role assignment.'],
            ]);
        }

        return DB::transaction(function () use ($invite, $role, $token, $userId, $classId) {
            $freshInvite = QRInvite::where('id', $invite->id)
                ->lockForUpdate()
                ->first();

            if (!$freshInvite || !$freshInvite->isValid()) {
                $msg = $freshInvite && $freshInvite->max_uses !== null && $freshInvite->use_count >= $freshInvite->max_uses
                    ? __('invite.max_uses_reached')
                    : __('invite.already_used');
                throw ValidationException::withMessages([
                    'invite' => [$msg],
                ]);
            }

            $user = \App\Models\User::find($userId);
            if (!$user) {
                throw ValidationException::withMessages([
                    'user' => ['User not found.'],
                ]);
            }

            if ($user->role->value === $role->value) {
                throw ValidationException::withMessages([
                    'invite' => ['You are already registered as a ' . $role->label() . '.'],
                ]);
            }

            $updateData = [
                'role' => $role->value,
                'invite_id' => $freshInvite->id,
            ];

            // Class is chosen by the user during accept, not carried by the invite
            if ($classId) {
                $classe = Classe::where('id', $classId)
                    ->where('church_id', $freshInvite->church_id)
                    ->first();
                if ($classe) {
                    $updateData['class_id'] = $classId;
                }
            }

            if ($role === UserRole::Member) {
                $updateData['servant_id'] = $freshInvite->created_by;
            }

            $user->update($updateData);

            $used = $freshInvite->markAsUsed($userId);
            if (!$used) {
                throw ValidationException::withMessages([
                    'invite' => [__('invite.max_uses_reached')],
                ]);
            }

            $user->tokens()->delete();

            Log::info('Invite accepted — tokens revoked, re-login required', [
                'invite_id' => $freshInvite->id,
                'token' => $token,
                'user_id' => $userId,
                'role' => $role->value,
            ]);

            return [
                'user' => $user->fresh()->load(['classe', 'servant']),
                'role' => $role,
                'message' => 'Role updated. Please log in again with your new permissions.',
            ];
        });
    }

    public function findById(int $id)
    {
        return $this->qrInviteRepository->findById($id);
    }

    public function revokeInvite(int $id): bool
    {
        return $this->qrInviteRepository->revoke($id);
    }

    public function getInviteUrl(string $token): string
    {
        return config('app.frontend_url') . '/invite/' . urlencode($token);
    }

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
