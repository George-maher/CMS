<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Notification */
class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'feedback_id' => $this->feedback_id,
            'points_id' => $this->points_id,
            'title' => $this->title,
            'body' => $this->body,
            'type' => $this->type,
            'is_read' => $this->is_read,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
            'event' => $this->when($this->relationLoaded('event') && $this->event, function () {
                /** @var \App\Models\Event $event */
                $event = $this->event;
                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'preview' => $event->description ? (mb_strlen($event->description) > 150 ? mb_substr($event->description, 0, 150) . '...' : $event->description) : null,
                ];
            }),
            'feedback' => $this->when($this->relationLoaded('feedback') && $this->feedback, function () {
                /** @var \App\Models\Feedback $feedback */
                $feedback = $this->feedback;
                return [
                    'id' => $feedback->id,
                    'message' => $feedback->message,
                    'created_at' => $feedback->created_at,
                    'replies' => $feedback->relationLoaded('replies')
                        ? $feedback->replies->map(function ($r) {
                            /** @var \App\Models\FeedbackReply $r */
                            return [
                                'id' => $r->id,
                                'message' => $r->message,
                                'user' => ['id' => $r->user_id, 'name' => ($r->user->name ?? 'Unknown')],
                                'created_at' => $r->created_at,
                            ];
                        })
                        : [],
                ];
            }),
            'point' => $this->when($this->relationLoaded('point') && $this->point, function () {
                /** @var \App\Models\Point $point */
                $point = $this->point;
                return [
                    'id' => $point->id,
                    'points' => $point->points,
                    'description' => $point->description,
                    'created_at' => $point->created_at,
                ];
            }),
        ];
    }
}
