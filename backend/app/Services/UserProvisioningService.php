<?php

namespace App\Services;

use App\Contracts\UserProvisioningServiceInterface;
use App\Contracts\UserRepositoryInterface;
use App\Enums\ProvisioningChannel;
use App\Models\User;
use LogicException;

/**
 * Single enforcement point for the account-provisioning rules.
 *
 * Every insert into `users` goes through here so that two guarantees hold
 * structurally rather than by convention:
 *
 *  1. An account created through a trusted/internal channel starts with
 *     `email_verified_at` set, so it can authenticate immediately.
 *     Untrusted (anonymous) submissions stay unverified.
 *
 *  2. The invite-backed channels cannot be used without a validated invite:
 *     `invite_id` is mandatory for them, which means the caller must already
 *     have gone through QR invite validation (token, expiry, revocation,
 *     use count, tenant, stage and class binding).
 *
 * Invitation validity itself is deliberately NOT re-implemented here. The
 * authoritative checks live in QRInviteService::validateTokenForRegistration()
 * and in the `lockForUpdate()` re-fetch inside AuthService::register(), which
 * also consume the invite atomically. This service only decides what a
 * *successfully authorized* creation implies about the email address.
 */
class UserProvisioningService implements UserProvisioningServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, ProvisioningChannel $channel): User
    {
        $attributes = $this->applyVerification($attributes, $channel);

        if ($channel->requiresInvite() && empty($attributes['invite_id'])) {
            // Reaching an invite-backed channel without a validated invite id
            // would mean the trust decision was made without evidence.
            throw new LogicException(
                "Provisioning channel [{$channel->value}] requires a validated invite_id."
            );
        }

        return $this->userRepository->create($attributes);
    }

    /** @return array<string, mixed> */
    public function verificationAttributes(ProvisioningChannel $channel): array
    {
        return ['email_verified_at' => $channel->establishesEmailOwnership() ? now() : null];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applyVerification(array $attributes, ProvisioningChannel $channel): array
    {
        // An explicitly supplied non-null timestamp (e.g. an imported legacy
        // value) is never overwritten; otherwise the channel decides. The key
        // is always present, so "unverified" is recorded as an explicit null
        // instead of being implied by omission.
        $attributes['email_verified_at'] ??= $this->verificationAttributes($channel)['email_verified_at'];

        return $attributes;
    }
}
