<?php

namespace App\Contracts;

use App\Enums\ProvisioningChannel;
use App\Models\User;

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
interface UserProvisioningServiceInterface
{
    /**
     * Insert a user under an explicit provisioning channel.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ProvisioningChannel $channel): User;

    /**
     * The `email_verified_at` value implied by a channel.
     *
     * Exposed so that the platform-approval flow can promote an already-created
     * (unverified) applicant to verified using the same single decision, instead
     * of hard-coding `now()` at the call site.
     *
     * @return array<string, mixed>
     */
    public function verificationAttributes(ProvisioningChannel $channel): array;
}
