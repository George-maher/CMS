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

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var int|null $id */
        $id = $data['id'] ?? null;
        /** @var string|QRInviteType $rawType */
        $rawType = $data['type'];
        /** @var string $type */
        $type = $rawType instanceof QRInviteType ? $rawType->value : $rawType;
        /** @var string $token */
        $token = $data['token'];
        /** @var int $created_by */
        $created_by = $data['created_by'];
        /** @var int|null $class_year_id */
        $class_year_id = $data['class_year_id'] ?? null;
        /** @var int|null $used_by */
        $used_by = $data['used_by'] ?? null;
        /** @var string $expires_at */
        $expires_at = $data['expires_at'];
        /** @var string|null $used_at */
        $used_at = $data['used_at'] ?? null;
        /** @var bool $is_revoked */
        $is_revoked = $data['is_revoked'] ?? false;
        /** @var bool $is_single_use */
        $is_single_use = $data['is_single_use'] ?? true;
        /** @var int $max_uses */
        $max_uses = $data['max_uses'] ?? 1;
        /** @var int $use_count */
        $use_count = $data['use_count'] ?? 0;

        return new self(
            id: $id,
            type: $data['type'] instanceof QRInviteType ? $data['type'] : QRInviteType::from($type),
            token: $token,
            created_by: $created_by,
            class_year_id: $class_year_id,
            used_by: $used_by,
            expires_at: $expires_at,
            used_at: $used_at,
            is_revoked: $is_revoked,
            is_single_use: $is_single_use,
            max_uses: $max_uses,
            use_count: $use_count,
        );
    }

    /** @return array<string, mixed> */
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
