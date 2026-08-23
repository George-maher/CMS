<?php

namespace App\Enums;

enum EventPaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank Transfer',
            self::Other => 'Other',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
