<?php

namespace Database\Factories;

use App\Enums\QRInviteType;
use App\Models\Church;
use App\Models\QRInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class QRInviteFactory extends Factory
{
    protected $model = QRInvite::class;

    public function definition(): array
    {
        return [
            'type' => QRInviteType::ServantToMemberInvite,
            'token' => Str::random(64),
            'created_by' => User::factory(),
            'church_id' => Church::factory(),
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
            'is_revoked' => false,
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subHour()]);
    }

    public function used(): static
    {
        return $this->state([
            'use_count' => 1,
            'used_at' => now(),
            'used_by' => User::factory(),
        ]);
    }

    public function adminToServant(): static
    {
        return $this->state(['type' => QRInviteType::AdminToServantInvite]);
    }
}
