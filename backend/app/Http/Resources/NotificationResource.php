<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
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
            'event' => $this->when($this->relationLoaded('event') && $this->event, fn() => [
                'id' => $this->event->id,
                'name' => $this->event->name,
                'preview' => $this->event->description ? (mb_strlen($this->event->description) > 150 ? mb_substr($this->event->description, 0, 150) . '...' : $this->event->description) : null,
            ]),
            'feedback' => $this->when($this->relationLoaded('feedback') && $this->feedback, fn() => [
                'id' => $this->feedback->id,
                'message' => $this->feedback->message,
                'created_at' => $this->feedback->created_at,
                'replies' => $this->feedback->relationLoaded('replies')
                    ? $this->feedback->replies->map(fn($r) => [
                        'id' => $r->id,
                        'message' => $r->message,
                        'user' => ['id' => $r->user_id, 'name' => $r->user?->name ?? 'Unknown'],
                        'created_at' => $r->created_at,
                    ])
                    : [],
            ]),
            'point' => $this->when($this->relationLoaded('point') && $this->point, fn() => [
                'id' => $this->point->id,
                'points' => $this->point->points,
                'description' => $this->point->description,
                'created_at' => $this->point->created_at,
            ]),
        ];
    }
}
