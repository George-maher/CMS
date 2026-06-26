<?php

namespace App\Enums;

enum FeedbackCategory: string
{
    case Complaint = 'complaint';
    case Suggestion = 'suggestion';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Complaint => 'Complaint',
            self::Suggestion => 'Suggestion',
            self::Other => 'Other',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
