<?php

namespace App\DTOs;

readonly class AttendanceDTO
{
    public function __construct(
        public ?int $id,
        public int $user_id,
        public int $recorded_by,
        public ?int $class_year_id,
        public ?int $event_id,
        public string $attended_at,
        public int $points_earned,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            user_id: $data['user_id'],
            recorded_by: $data['recorded_by'],
            class_year_id: $data['class_year_id'] ?? null,
            event_id: $data['event_id'] ?? null,
            attended_at: $data['attended_at'],
            points_earned: $data['points_earned'] ?? 0,
        );
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->user_id,
            'recorded_by' => $this->recorded_by,
            'class_year_id' => $this->class_year_id,
            'event_id' => $this->event_id,
            'attended_at' => $this->attended_at,
            'points_earned' => $this->points_earned,
        ];
    }
}
