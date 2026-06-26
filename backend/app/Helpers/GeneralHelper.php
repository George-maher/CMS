<?php

namespace App\Helpers;

class GeneralHelper
{
    public static function sanitizePhone(?string $phone): ?string
    {
        if ($phone === null) return null;
        return preg_replace('/[^0-9+]/', '', $phone);
    }

    public static function truncate(string $text, int $length = 100, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) return $text;
        return mb_substr($text, 0, $length) . $suffix;
    }

    public static function generateRandomColor(): string
    {
        return sprintf('#%06x', random_int(0, 0xFFFFFF));
    }

    public static function slugify(string $text): string
    {
        return strtolower(trim(preg_replace('/[^a-zA-Z0-9-]+/', '-', $text), '-'));
    }
}
