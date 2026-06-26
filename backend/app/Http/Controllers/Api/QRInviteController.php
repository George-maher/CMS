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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class QRInviteController extends Controller
{
    public function __construct(
        private readonly QRInviteServiceInterface $qrInviteService,
    ) {}

    public function store(CreateQRInviteRequest $request): JsonResponse
    {
        $user = $request->user();
        $type = QRInviteType::from($request->validated()['type']);

        if ($user->role === UserRole::Servant && !in_array($type, [QRInviteType::ServantToMemberInvite, QRInviteType::AttendanceQR], true)) {
            throw ValidationException::withMessages([
                'type' => ['Servants can only create member or attendance invitations.'],
            ]);
        }

        if ($user->role === UserRole::Admin && !in_array($type, [QRInviteType::AdminToServantInvite, QRInviteType::ServantToMemberInvite, QRInviteType::AttendanceQR], true)) {
            throw ValidationException::withMessages([
                'type' => ['Admins can only create servant, member, or attendance invitations.'],
            ]);
        }

        $data = $request->validated();

        $result = $this->qrInviteService->createInvite(
            data: $data,
            creatorId: $user->id
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
        $result = $this->qrInviteService->getInviteDetails($token);

        return response()->json([
            'data' => [
                'valid' => $result['valid'],
                'type' => $result['type']->value,
                'type_label' => $result['type_label'],
                'role' => $result['role']?->value,
                'role_label' => $result['role_label'] ?? null,
                'creator_name' => $result['creator_name'],
                'creator_class_id' => $result['creator_class_id'] ?? null,
                'creator_class_name' => $result['creator_class_name'] ?? null,
                'class_id' => $result['class_id'] ?? null,
                'class_name' => $result['class_name'] ?? null,
                'classes' => $result['classes'] ?? [],
                'expires_at' => $result['expires_at'],
                'is_expired' => $result['is_expired'],
                'is_used' => $result['is_used'],
                'is_revoked' => $result['is_revoked'],
                'use_count' => $result['invite']->use_count,
                'max_uses' => $result['invite']->max_uses,
                'remaining_uses' => $result['invite']->max_uses !== null
                    ? max(0, $result['invite']->max_uses - $result['invite']->use_count)
                    : null,
                'usage_label' => $result['invite']->max_uses
                    ? ($result['invite']->use_count . ' / ' . $result['invite']->max_uses)
                    : null,
                'used_by_users' => $result['invite']->used_by_users,
            ],
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $result = $this->qrInviteService->acceptInvite(
            token: $token,
            userId: $request->user()->id,
            classId: $request->input('class_id'),
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

        if (!$invite) {
            return response()->json(['message' => 'QR invite not found.'], 404);
        }

        $user = $request->user();
        if ($user->role === UserRole::Servant && $invite->created_by !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $this->qrInviteService->revokeInvite($id);

        return response()->json([
            'message' => 'QR invite revoked successfully.',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'type', 'created_by', 'is_revoked', 'status',
            'class_id', 'date_from', 'date_to',
            'expires_from', 'expires_to', 'search',
        ]);

        if ($request->user()->role === UserRole::Servant) {
            $filters['created_by'] = $request->user()->id;
            // Ignore class_id filter — servants only see their own invites
            unset($filters['class_id']);
        }

        $result = $this->qrInviteService->listInvites(
            perPage: $request->input('per_page', 15),
            filters: $filters
        );

        QRInviteResource::loadUsedByUsersBatch($result['data']);

        return response()->json([
            'data' => QRInviteResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }
}
