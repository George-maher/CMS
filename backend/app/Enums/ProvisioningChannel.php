<?php

namespace App\Enums;

/**
 * The provenance of a `users` row — i.e. *who* caused this account to exist.
 *
 * This is the single input that decides whether the account starts life with
 * `email_verified_at` set, so the business rule
 *
 *   "accounts created through a trusted/internal flow are verified by default"
 *
 * is expressed in exactly one place instead of being re-implemented as a
 * scattered `email_verified_at = now()` in every controller and service.
 *
 * A channel is TRUSTED only when a party who already holds authority over the
 * tenant has vouched for the address:
 *
 *  - an authenticated church admin / stage admin created the row, or
 *  - the row was created while consuming a valid, single-use, expiring,
 *    revocable 64-character invite secret, or
 *  - a platform admin approved the application, or
 *  - an authenticated admin approved a public membership request, or
 *  - an operator with database access ran a CLI bootstrap.
 *
 * Anything an anonymous caller can initiate is UNTRUSTED, regardless of how
 * plausible the payload looks.
 */
enum ProvisioningChannel: string
{
    /** Admin-created user (POST /users, permission:manage_users). */
    case AdminCreated = 'admin_created';

    /** Account created while consuming a valid admin_to_servant invite. */
    case QrInviteServant = 'qr_invite_servant';

    /** Account created while consuming a valid servant_to_member invite. */
    case QrInviteMember = 'qr_invite_member';

    /** Church admin account created when the platform approves an application. */
    case ChurchApplicationApproved = 'church_application_approved';

    /** Account created when an admin approves a public membership request. */
    case MembershipRequestApproved = 'membership_request_approved';

    /** Platform operator bootstrap via `php artisan app:reset-data`. */
    case PlatformBootstrap = 'platform_bootstrap';

    /**
     * Untrusted: an anonymous visitor submitted a church-application form.
     * The address is self-declared and unvouched, so the account must stay
     * unverified until a platform admin approves it.
     */
    case ChurchApplicationSubmitted = 'church_application_submitted';

    /**
     * Whether this channel attests that the account owns its email address.
     */
    public function establishesEmailOwnership(): bool
    {
        return match ($this) {
            // Self-declared by an anonymous caller, never vouched for.
            self::ChurchApplicationSubmitted => false,

            self::AdminCreated,
            self::QrInviteServant,
            self::QrInviteMember,
            self::ChurchApplicationApproved,
            self::MembershipRequestApproved,
            self::PlatformBootstrap => true,
        };
    }

    /**
     * Whether reaching this channel requires a validated QR invite.
     *
     * Used as a structural guard so the invite-backed channels can never be
     * used to bypass invite validation.
     */
    public function requiresInvite(): bool
    {
        return $this === self::QrInviteServant || $this === self::QrInviteMember;
    }
}
