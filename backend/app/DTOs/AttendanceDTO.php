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

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var int|null $id */
        $id = $data['id'] ?? null;
        /** @var int $user_id */
        $user_id = $data['user_id'];
        /** @var int $recorded_by */
        $recorded_by = $data['recorded_by'];
        /** @var int|null $class_year_id */
        $class_year_id = $data['class_year_id'] ?? null;
        /** @var int|null $event_id */
        $event_id = $data['event_id'] ?? null;
        /** @var string $attended_at */
        $attended_at = $data['attended_at'];
        /** @var int $points_earned */
        $points_earned = $data['points_earned'] ?? 0;

        return new self(
            id: $id,
            user_id: $user_id,
            recorded_by: $recorded_by,
            class_year_id: $class_year_id,
            event_id: $event_id,
            attended_at: $attended_at,
            points_earned: $points_earned,
        );
    }

    /** @return array<string, mixed> */
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
