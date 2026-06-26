<?php

namespace App\Enums;

enum PointType: string
{
    case Attendance = 'attendance';
    case Bonus = 'bonus';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Attendance => 'Attendance Reward',
            self::Bonus => 'Bonus Points',
            self::Adjustment => 'Points Adjustment',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
