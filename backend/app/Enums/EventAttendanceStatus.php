<?php

namespace App\Enums;

enum EventAttendanceStatus: string
{
    case NotCheckedIn = 'not_checked_in';
    case CheckedIn = 'checked_in';
    case Absent = 'absent';

    public function label(): string
    {
        return match ($this) {
            self::NotCheckedIn => 'Not Checked In',
            self::CheckedIn => 'Checked In',
            self::Absent => 'Absent',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
