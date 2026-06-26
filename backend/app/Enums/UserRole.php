<?php

namespace App\Enums;

enum UserRole: string
{
    case PlatformAdmin = 'platform_admin';
    case Admin = 'admin';
    case AssistantAdmin = 'assistant_admin';
    case Servant = 'servant';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'Platform Admin',
            self::Admin => 'Church Admin',
            self::AssistantAdmin => 'Assistant Admin',
            self::Servant => 'Servant',
            self::Member => 'Member',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function adminRoles(): array
    {
        return [self::Admin, self::AssistantAdmin];
    }
}
