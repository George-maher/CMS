<?php

namespace App\Http\Resources;

use App\Http\Resources\AttendanceContextResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Attendance */
class AttendanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => new UserResource($this->whenLoaded('user')),
            'recorder' => $this->when($this->recorder !== null, function () {
                /** @var \App\Models\User $recorder */
                $recorder = $this->recorder;
                return [
                    'id' => $recorder->id,
                    'name' => $recorder->name,
                ];
            }),
            'classe' => $this->when($this->relationLoaded('classe') && $this->classe, function () {
                /** @var \App\Models\Classe $classe */
                $classe = $this->classe;
                return [
                    'id' => $classe->id,
                    'name' => $classe->name,
                ];
            }),
            'event' => $this->when($this->relationLoaded('event') && $this->event, function () {
                /** @var \App\Models\Event $event */
                $event = $this->event;
                return [
                    'id' => $event->id,
                    'name' => $event->name,
                ];
            }),
            'attendance_context' => $this->when($this->relationLoaded('attendanceContext') && $this->attendanceContext, function () {
                /** @var \App\Models\AttendanceContext $attendanceContext */
                $attendanceContext = $this->attendanceContext;
                return [
                    'id' => $attendanceContext->id,
                    'name' => $attendanceContext->name,
                    'name_ar' => $attendanceContext->name_ar,
                    'slug' => $attendanceContext->slug,
                ];
            }),
            'attendance_context_id' => $this->attendance_context_id,
            'method' => $this->method,
            'attended_at' => $this->attended_at,
            'points_earned' => $this->points_earned,
            'created_at' => $this->created_at,
        ];
    }
}
