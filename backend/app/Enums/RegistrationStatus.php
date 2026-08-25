<?php

namespace App\Enums;

enum RegistrationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Waitlisted = 'waitlisted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Booked = 'booked';
    case NotReserved = 'not_reserved';
    case Thinking = 'thinking';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Cancelled => 'Cancelled',
            self::Waitlisted => 'Waitlisted',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Booked => 'حجزت',
            self::NotReserved => 'لسه ما حجزتش',
            self::Thinking => 'بفكر',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
