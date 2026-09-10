<?php

namespace App\Http\Controllers\Api;

use App\Contracts\MemberProfileServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberProfileController extends Controller
{
    public function __construct(
        private readonly MemberProfileServiceInterface $memberProfileService,
    ) {}

    public function getProfile(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $userId */
        $userId = $user->id;
        $result = $this->memberProfileService->getProfile($id, $userId);

        if (! $result['found']) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        if (isset($result['authorized']) && ! $result['authorized']) {
            return response()->json(['message' => 'Unauthorized to view this member profile.'], 403);
        }

        /** @var User $member */
        $member = $result['member'];

        return response()->json([
            'data' => [
                'member' => [
                    'id' => $member->id,
                    'member_id' => $member->member_id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'phone' => $member->phone,
                    'address' => $member->address,
                    'member_address' => $member->member_address,
                    'avatar' => $member->avatar,
                    'birthday' => $member->birthday?->format('Y-m-d'),
                    'age' => $member->age,
                    'role' => $member->role?->value,
                    'role_label' => $member->role?->label(),
                    'is_active' => $member->is_active,
                    'class_id' => $member->class_id,
                    'classe' => $member->classe ? [
                        'id' => $member->classe->id,
                        'name' => $member->classe->name,
                        'stage' => $member->classe->stage ? [
                            'id' => $member->classe->stage->id,
                            'name' => $member->classe->stage->name,
                        ] : null,
                    ] : null,
                    'servant' => $member->servant ? [
                        'id' => $member->servant->id,
                        'name' => $member->servant->name,
                        'phone' => $member->servant->phone,
                    ] : null,
                    'total_points' => $member->total_points,
                    'created_at' => $member->created_at?->toISOString(),
                ],
                'attendance' => $result['attendance'],
                'spiritual' => $result['spiritual'],
            ],
        ]);
    }
}
