<?php

namespace App\Http\Controllers\Api;

use App\Contracts\QRInviteServiceInterface;
use App\Contracts\ScopeResolverInterface;
use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateQRInviteRequest;
use App\Http\Resources\QRInviteResource;
use App\Http\Resources\UserResource;
use App\Models\Classe;
use App\Models\QRInvite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class QRInviteController extends Controller
{
    public function __construct(
        private readonly QRInviteServiceInterface $qrInviteService,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function store(CreateQRInviteRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var string $typeValue */
        $typeValue = $request->validated()['type'];
        $type = QRInviteType::from($typeValue);

        if (! in_array($type->value, $this->allowedInviteTypesFor($user), true)) {
            throw ValidationException::withMessages([
                'type' => [$this->inviteTypeRestrictionMessage($user)],
            ]);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        if (! $user->isAdmin() && ! $user->isPlatformAdmin()) {
            // The stage is derived server-side from the authenticated user's
            // scope. Any client-supplied stage_id is ignored for stage-scoped
            // roles, and the class (if any) must belong to that scope as well.
            /** @var int|null $stageId */
            $stageId = $this->scopeResolver->userStageId($user);
            if ($stageId === null) {
                throw ValidationException::withMessages([
                    'stage_id' => [__('invite.stage_required')],
                ]);
            }
            $data['stage_id'] = $stageId;

            /** @var int|null $classId */
            $classId = isset($data['class_id']) && is_numeric($data['class_id']) ? (int) $data['class_id'] : null;
            if ($classId !== null) {
                $allowedClasses = $this->scopeResolver->allowedClassIds($user);
                if ($allowedClasses === null || ! in_array($classId, $allowedClasses, true)) {
                    throw ValidationException::withMessages([
                        'class_id' => [__('invite.class_stage_mismatch')],
                    ]);
                }
            }
        }

        /** @var int $creatorId */
        $creatorId = $user->id;
        /** @var array{invite: QRInvite, url: string} $result */
        $result = $this->qrInviteService->createInvite(
            data: $data,
            creatorId: $creatorId,
        );

        return response()->json([
            'message' => 'QR invite created successfully.',
            'data' => [
                'invite' => new QRInviteResource($result['invite']),
                'url' => $result['url'],
            ],
        ], 201);
    }

    public function validateToken(string $token): JsonResponse
    {
        /** @var array{valid: bool, invite: QRInvite, type: QRInviteType} $result */
        $result = $this->qrInviteService->validateToken($token);
        $invite = $result['invite'];
        $invite->load(['stage']);
        $classesQuery = Classe::where('church_id', $invite->church_id);
        if ($invite->stage_id !== null) {
            // Only classes inside the invitation's stage are selectable.
            $classesQuery->where('stage_id', $invite->stage_id);
        }
        $classes = $classesQuery->orderBy('name')->get(['id', 'name']);

        $data = [
            'valid' => $result['valid'],
            'type' => $result['type']->value,
            'invite' => new QRInviteResource($invite),
            'stage_id' => $invite->stage_id,
            'stage_name' => $invite->stage?->name,
            'classes' => $classes->toArray(),
            'attendance_context_id' => $invite->attendance_context_id,
            'attendance_context' => $invite->attendanceContext ? [
                'id' => $invite->attendanceContext->id,
                'name' => $invite->attendanceContext->name,
                'slug' => $invite->attendanceContext->slug,
            ] : null,
        ];

        if ($result['type'] === QRInviteType::ServantToMemberInvite) {
            $invite->load('creator.classe');
            $data['creator_class_id'] = $invite->creator?->classe?->id;
            $data['creator_class_name'] = $invite->creator?->classe?->name;
        }

        return response()->json([
            'data' => $data,
        ]);
    }

    public function details(string $token): JsonResponse
    {
        /** @var array{valid: bool, invite: QRInvite, type: QRInviteType, type_label: string, role: UserRole|null, role_label: string|null, creator_name: string|null, creator_class_id: int|null, creator_class_name: string|null, class_id: int|null, class_name: string|null, classes: array<int, array<string, mixed>>, expires_at: mixed, is_expired: bool, is_used: bool, is_revoked: bool} $result */
        $result = $this->qrInviteService->getInviteDetails($token);

        /** @var QRInvite $invite */
        $invite = $result['invite'];

        return response()->json([
            'data' => [
                'valid' => $result['valid'],
                'type' => $result['type']->value,
                'type_label' => $result['type_label'],
                'role' => $result['role']?->value,
                'role_label' => $result['role_label'] ?? null,
                'creator_name' => $result['creator_name'] ?? null,
                'creator_class_id' => $result['creator_class_id'] ?? null,
                'creator_class_name' => $result['creator_class_name'] ?? null,
                'class_id' => $result['class_id'] ?? null,
                'class_name' => $result['class_name'] ?? null,
                'classes' => $result['classes'] ?? [],
                'expires_at' => $result['expires_at'] ?? null,
                'is_expired' => $result['is_expired'] ?? false,
                'is_used' => $result['is_used'] ?? false,
                'is_revoked' => $result['is_revoked'] ?? false,
                'use_count' => $invite->use_count,
                'max_uses' => $invite->max_uses,
                'remaining_uses' => $invite->max_uses !== null
                    ? max(0, $invite->max_uses - $invite->use_count)
                    : null,
                'usage_label' => $invite->max_uses
                    ? ($invite->use_count.' / '.$invite->max_uses)
                    : null,
                'used_by_users' => $invite->used_by_users,
            ],
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $classId = $request->integer('class_id') ?: null;
        /** @var int $userId */
        $userId = (int) $user->id;
        /** @var array{message: string, user: User, role: UserRole} $result */
        $result = $this->qrInviteService->acceptInvite(
            token: $token,
            userId: $userId,
            classId: $classId,
        );

        return response()->json([
            'message' => $result['message'] ?? 'Invite accepted successfully. Please log in again with your new role.',
            'data' => [
                'user' => new UserResource($result['user']),
                'role' => $result['role']?->value,
                'requires_relogin' => true,
            ],
        ]);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $invite = $this->qrInviteService->findById($id);

        if (! $invite) {
            return response()->json(['message' => 'QR invite not found.'], 404);
        }

        /** @var User $user */
        $user = $request->user();
        /** @var int $uid */
        $uid = $user->id;
        if ($user->role === UserRole::Servant && $invite->created_by !== $uid) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($user->isStageAdmin()
            && $invite->created_by !== $uid
            && ($invite->stage_id === null || $invite->stage_id !== $user->stage_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $this->qrInviteService->revokeInvite($id);

        return response()->json([
            'message' => 'QR invite revoked successfully.',
        ]);
    }

    public function rotate(Request $request, int $id): JsonResponse
    {
        $invite = $this->qrInviteService->findById($id);
        if (! $invite) {
            return response()->json(['message' => 'QR invite not found.'], 404);
        }

        /** @var User $user */
        $user = $request->user();
        if ($user->isServant() && $invite->created_by !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($user->isStageAdmin()
            && $invite->created_by !== $user->id
            && ($invite->stage_id === null || $invite->stage_id !== $user->stage_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if (! $user->isPlatformAdmin() && $invite->church_id !== $user->church_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $rotated = $this->qrInviteService->rotateInvite($id);

        return response()->json([
            'message' => 'QR invite token rotated successfully.',
            'data' => [
                'invite' => new QRInviteResource($rotated),
                'url' => $this->qrInviteService->getInviteUrl($rotated->token),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $filters */
        $filters = $request->only([
            'type', 'created_by', 'is_revoked', 'status',
            'class_id', 'date_from', 'date_to',
            'expires_from', 'expires_to', 'search',
        ]);

        /** @var User $currentUser */
        $currentUser = $request->user();
        if ($currentUser->role === UserRole::Servant) {
            $filters['created_by'] = $currentUser->id;
            // Ignore class_id filter — servants only see their own invites
            unset($filters['class_id']);
        }
        if ($currentUser->isStageAdmin()) {
            // Stage admins only see invitations scoped to their stage; any
            // client-supplied filters that could leak another stage are dropped.
            unset($filters['created_by'], $filters['class_id'], $filters['class_year_id']);
            if ($currentUser->stage_id !== null) {
                $filters['stage_id'] = $currentUser->stage_id;
            }
        }

        /** @var array{data: Collection<int, QRInvite>, meta: array<string, mixed>} $result */
        $result = $this->qrInviteService->listInvites(
            perPage: $request->integer('per_page', 15),
            filters: $filters
        );

        QRInviteResource::loadUsedByUsersBatch($result['data']);

        return response()->json([
            'data' => QRInviteResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    /**
     * Invite types a given role may create.
     *
     * @return array<int, string>
     */
    private function allowedInviteTypesFor(User $user): array
    {
        if ($user->isStageAdmin()) {
            return [
                QRInviteType::AdminToServantInvite->value,
                QRInviteType::ServantToMemberInvite->value,
                QRInviteType::AttendanceQR->value,
            ];
        }

        if ($user->isServant()) {
            return [
                QRInviteType::ServantToMemberInvite->value,
                QRInviteType::AttendanceQR->value,
            ];
        }

        return [
            QRInviteType::AdminToServantInvite->value,
            QRInviteType::ServantToMemberInvite->value,
            QRInviteType::AttendanceQR->value,
        ];
    }

    private function inviteTypeRestrictionMessage(User $user): string
    {
        if ($user->isStageAdmin()) {
            return 'Stage admins can only create member, servant, or attendance invitations within their stage.';
        }

        if ($user->isServant()) {
            return 'Servants can only create member or attendance invitations.';
        }

        return 'You are not allowed to create this invitation type.';
    }
}
