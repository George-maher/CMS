<?php

namespace App\Events;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttendanceRecorded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Attendance $attendance;

    public ?int $churchId;

    public function __construct(Attendance $attendance)
    {
        $this->attendance = $attendance;
        /** @var User|null $authUser */
        $authUser = auth()->user();
        $this->churchId = $attendance->church_id ?? $authUser?->church_id;
    }
}
