<?php

namespace App\Enums;

/**
 * The externally observable result of an email verification attempt.
 *
 * Deliberately coarse. An unauthenticated caller must not be able to tell
 * "no such account", "already verified", "no token issued" and "wrong token"
 * apart, so every failure collapses into `Failed` and the API layer returns one
 * indistinguishable response for all of them.
 *
 * Internal reasons are logged as metadata only (never the token itself).
 */
enum EmailVerificationOutcome: string
{
    /** The supplied token was valid, unexpired, matched, and was consumed. */
    case Verified = 'verified';

    /**
     * Any failure at all: unknown address, already verified, no token, expired
     * token, mismatched token, or the account is gone.
     */
    case Failed = 'failed';

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }
}
