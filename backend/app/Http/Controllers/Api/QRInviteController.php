<?php

namespace App\Http\Controllers\Api;

use App\Contracts\QRInviteServiceInterface;
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
    ) {}

    public function store(CreateQRInviteRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var string $typeValue */
        $typeValue = $request->validated()['type'];
        $type = QRInviteType::from($typeValue);

        if ($user->role === UserRole::Servant && ! in_array($type, [QRInviteType::ServantToMemberInvite, QRInviteType::AttendanceQR], true)) {
            throw ValidationException::withMessages([
                'type' => ['Servants can only create member or attendance invitations.'],
            ]);
        }

        if ($user->role === UserRole::Admin && ! in_array($type, [QRInviteType::AdminToServantInvite, QRInviteType::ServantToMemberInvite, QRInviteType::AttendanceQR], true)) {
            throw ValidationException::withMessages([
                'type' => ['Admins can only create servant, member, or attendance invitations.'],
            ]);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

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
        $classes = Classe::where('church_id', $invite->church_id)
            ->get(['id', 'name']);

        $data = [
            'valid' => $result['valid'],
            'type' => $result['type']->value,
            'invite' => new QRInviteResource($invite),
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

        $this->qrInviteService->revokeInvite($id);

        return response()->json([
            'message' => 'QR invite revoked successfully.',
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
}
