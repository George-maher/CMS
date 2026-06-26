<?php

namespace App\Http\Resources;

use App\Http\Resources\AttendanceContextResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => new UserResource($this->whenLoaded('user')),
            'recorder' => $this->when($this->recorder, fn() => [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
            ]),
            'classe' => $this->when($this->relationLoaded('classe') && $this->classe, fn() => [
                'id' => $this->classe->id,
                'name' => $this->classe->name,
            ]),
            'event' => $this->when($this->relationLoaded('event') && $this->event, fn() => [
                'id' => $this->event->id,
                'name' => $this->event->name,
            ]),
            'attendance_context' => $this->when($this->relationLoaded('attendanceContext') && $this->attendanceContext, fn() => [
                'id' => $this->attendanceContext->id,
                'name' => $this->attendanceContext->name,
                'name_ar' => $this->attendanceContext->name_ar,
                'slug' => $this->attendanceContext->slug,
            ]),
            'attendance_context_id' => $this->attendance_context_id,
            'method' => $this->method,
            'attended_at' => $this->attended_at,
            'points_earned' => $this->points_earned,
            'created_at' => $this->created_at,
        ];
    }
}
