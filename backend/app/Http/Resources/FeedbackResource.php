<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedbackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $authUser = $request->user();
        $isAdmin = $authUser && in_array($authUser->role, [UserRole::Admin, UserRole::AssistantAdmin], true);
        $isOwner = $authUser && $this->user_id === $authUser->id;

        // Servants cannot identify anonymous senders; admins and owners always can
        $canSeeIdentity = $isAdmin || $isOwner || !$this->is_anonymous;

        $userLoaded = $this->relationLoaded('user') && $this->user;

        return [
            'id' => $this->id,
            'message' => $this->message,
            'category' => $this->category?->value,
            'category_label' => $this->category?->label(),
            'is_resolved' => $this->is_resolved,
            'has_new_reply' => $this->has_new_reply,
            'is_anonymous' => $this->is_anonymous,

            // Backward-compat field for table columns
            'user' => $userLoaded
                ? ($canSeeIdentity ? ['id' => $this->user->id, 'name' => $this->user->name] : null)
                : null,

            // sender is always present; content varies by role
            'sender' => $userLoaded && $canSeeIdentity
                ? [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'phone' => $isAdmin ? $this->user->phone : null,
                    'class_id' => $this->user->class_id,
                    'class_name' => $this->user->classe?->name,
                    'stage_name' => $this->user->stage?->name ?? $this->user->classe?->stage?->name,
                ]
                : [
                    'id' => null,
                    'name' => 'Anonymous Member',
                    'phone' => null,
                    'class_id' => null,
                    'class_name' => null,
                    'stage_name' => null,
                ],

            // Badge for admins: this feedback was submitted anonymously
            'is_anonymous_to_servants' => $this->when($isAdmin, $this->is_anonymous),

            'replies' => $this->when($this->relationLoaded('replies'), fn() =>
                $this->replies->map(fn($reply) => [
                    'id' => $reply->id,
                    'message' => $reply->message,
                    'user' => [
                        'id' => $reply->user_id,
                        'name' => $reply->user?->name ?? 'Unknown',
                    ],
                    'created_at' => $reply->created_at,
                ])
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
