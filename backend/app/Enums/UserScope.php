<?php

namespace App\Enums;

enum UserScope: string
{
    case Church = 'church';
    case Stage = 'stage';
    case ClassScope = 'class';
    case Self = 'self';

    public function label(): string
    {
        return match ($this) {
            self::Church => 'Whole church',
            self::Stage => 'Stage',
            self::ClassScope => 'Class',
            self::Self => 'Self only',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        /** @var array<int, string> */
        return array_column(self::cases(), 'value');
    }
}
