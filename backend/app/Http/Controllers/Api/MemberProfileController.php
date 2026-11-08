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

        // F-04: a member profile must not disclose its own existence to a
        // caller who is not allowed to read it. A missing id, a member of
        // another church, and a member of another stage/class all produce the
        // exact same status code and byte-identical body, so the endpoint
        // cannot be used as an existence oracle. The authorization decision
        // itself is still made by MemberProfileService::canViewProfile(); only
        // the externally observable response is unified.
        if (! $result['found'] || (isset($result['authorized']) && ! $result['authorized'])) {
            return response()->json(['message' => 'Member not found.'], 404);
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
