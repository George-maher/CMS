<?php

namespace App\Helpers;

use Illuminate\Support\Str;

class QrHelper
{
    public const TOKEN_LENGTH = 64;

    public static function generateToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    public static function generateInviteUrl(string $token): string
    {
        return url('/invite/' . $token);
    }

    public static function generateAttendanceUrl(string $token): string
    {
        return url('/attendance/' . $token);
    }
}
