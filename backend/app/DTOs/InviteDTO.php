<?php

namespace App\DTOs;

use App\Enums\QRInviteType;

readonly class InviteDTO
{
    public function __construct(
        public ?int $id,
        public QRInviteType $type,
        public string $token,
        public int $created_by,
        public ?int $class_year_id,
        public ?int $used_by,
        public string $expires_at,
        public ?string $used_at,
        public bool $is_revoked,
        public bool $is_single_use,
        public int $max_uses,
        public int $use_count,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            type: $data['type'] instanceof QRInviteType ? $data['type'] : QRInviteType::from($data['type']),
            token: $data['token'],
            created_by: $data['created_by'],
            class_year_id: $data['class_year_id'] ?? null,
            used_by: $data['used_by'] ?? null,
            expires_at: $data['expires_at'],
            used_at: $data['used_at'] ?? null,
            is_revoked: $data['is_revoked'] ?? false,
            is_single_use: $data['is_single_use'] ?? true,
            max_uses: $data['max_uses'] ?? 1,
            use_count: $data['use_count'] ?? 0,
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'token' => $this->token,
            'created_by' => $this->created_by,
            'class_year_id' => $this->class_year_id,
            'used_by' => $this->used_by,
            'expires_at' => $this->expires_at,
            'used_at' => $this->used_at,
            'is_revoked' => $this->is_revoked,
            'is_single_use' => $this->is_single_use,
            'max_uses' => $this->max_uses,
            'use_count' => $this->use_count,
        ];
    }
}
