<?php

namespace App\Contracts;

use App\Enums\EmailVerificationOutcome;
use App\Models\User;

/**
 * Owns the email verification credential lifecycle.
 *
 * Security invariants enforced here (and nowhere else):
 *
 *  1. The raw token is NEVER persisted. Only `Hash::make($rawToken)` is stored,
 *     so a database dump cannot be replayed.
 *  2. The raw token is never written to any log, audit record or exception
 *     message.
 *  3. Verification is single-use: consuming it nulls the stored hash.
 *  4. Every failure collapses into one indistinguishable outcome so the
 *     endpoint cannot be used as an account-existence oracle.
 *  5. The notification carrying the raw token is queued with an *encrypted*
 *     payload, so the `jobs` table never holds a readable token.
 */
interface EmailVerificationServiceInterface
{
    /**
     * Issue a fresh token for the account, replacing any previous one.
     *
     * Returns the raw token so the caller can build the delivery URL. The
     * caller MUST NOT log it.
     */
    public function issue(User $user): string;

    /**
     * Issue a token and hand the link to the delivery channel.
     *
     * @return bool whether the message was handed to the transport
     */
    public function issueAndDispatch(User $user): bool;

    /**
     * Resend to an arbitrary (unauthenticated) address.
     *
     * Always reports the same thing back so the caller cannot probe for
     * account existence.
     *
     * @return bool whether a message was actually handed to the transport
     */
    public function resendForEmail(string $email): bool;

    /**
     * Consume a verification token.
     *
     * The comparison is constant-work with respect to account existence: a
     * missing account still pays for a hash comparison before returning.
     */
    public function verify(string $email, string $rawToken): EmailVerificationOutcome;
}
