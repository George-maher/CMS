<?php

namespace App\Helpers;

use Carbon\Carbon;

class DateHelper
{
    public static function formatDate(?Carbon $date, string $format = 'Y-m-d'): ?string
    {
        return $date?->format($format);
    }

    public static function formatDateTime(?Carbon $date, string $format = 'Y-m-d H:i:s'): ?string
    {
        return $date?->format($format);
    }

    public static function isToday(?Carbon $date): bool
    {
        return $date?->isToday() ?? false;
    }

    public static function daysUntil(?Carbon $date): ?int
    {
        return $date ? now()->startOfDay()->diffInDays($date->startOfDay(), false) : null;
    }

    public static function age(?Carbon $birthDate): ?int
    {
        return $birthDate?->age;
    }
}
