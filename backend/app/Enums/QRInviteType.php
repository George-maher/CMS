<?php

namespace App\Enums;

enum QRInviteType: string
{
    case AdminToServantInvite = 'admin_to_servant_invite';
    case ServantToMemberInvite = 'servant_to_member_invite';
    case AttendanceQR = 'attendance_qr';

    public function label(): string
    {
        return match ($this) {
            self::AdminToServantInvite => 'Admin to Servant Invite',
            self::ServantToMemberInvite => 'Servant to Member Invite',
            self::AttendanceQR => 'Attendance QR',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function targetRole(): ?UserRole
    {
        return match ($this) {
            self::AdminToServantInvite => UserRole::Servant,
            self::ServantToMemberInvite => UserRole::Member,
            self::AttendanceQR => null,
        };
    }

    public function isInvite(): bool
    {
        return $this !== self::AttendanceQR;
    }
}
