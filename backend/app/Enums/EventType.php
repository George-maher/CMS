<?php

namespace App\Enums;

enum EventType: string
{
    case Service = 'service';
    case Trip = 'trip';
    case Meeting = 'meeting';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Service',
            self::Trip => 'Trip',
            self::Meeting => 'Meeting',
            self::Other => 'Other',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
