<?php

namespace App\Enums;

enum EventType: string
{
    case Service = 'service';
    case Conference = 'conference';
    case Trip = 'trip';
    case Meeting = 'meeting';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Service',
            self::Conference => 'Conference',
            self::Trip => 'Trip',
            self::Meeting => 'Meeting',
            self::Other => 'Other',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
