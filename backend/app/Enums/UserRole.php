<?php

namespace App\Enums;

enum UserRole: string
{
    case PlatformAdmin = 'platform_admin';
    case Admin = 'admin';
    case AssistantAdmin = 'assistant_admin';
    case StageAdmin = 'stage_admin';
    case Servant = 'servant';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'Platform Admin',
            self::Admin => 'Church Admin',
            self::AssistantAdmin => 'Assistant Admin',
            self::StageAdmin => 'Stage Admin',
            self::Servant => 'Servant',
            self::Member => 'Member',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        /** @var array<int, string> */
        return array_column(self::cases(), 'value');
    }

    /**
     * Every role that carries management authority over other users.
     *
     * This is the single classification used to decide who may grant, change or
     * revoke a privileged role, and who may reset another user's password. It
     * lives on the enum so a controller and a service can never disagree about
     * the answer.
     *
     * `platform_admin` is deliberately excluded: it is a platform-tier role
     * which is never assignable inside a church tenant.
     *
     * @return array<int, self>
     */
    public static function privileged(): array
    {
        return [self::Admin, self::AssistantAdmin, self::StageAdmin];
    }

    /** @return array<int, string> */
    public static function privilegedValues(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::privileged());
    }

    /**
     * Whether this role is a tenant-level management role.
     */
    public function isPrivileged(): bool
    {
        return in_array($this, self::privileged(), true);
    }
}
