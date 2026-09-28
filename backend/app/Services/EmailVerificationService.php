<?php

namespace App\Services;

use App\Contracts\EmailVerificationServiceInterface;
use App\Contracts\MailConfigurationValidatorInterface;
use App\Enums\EmailVerificationOutcome;
use App\Models\Scopes\ChurchScope;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Implementation of the email verification credential lifecycle.
 *
 * Design notes
 * ------------
 * * The raw token exists only as a local variable and inside the outbound
 *   notification. It is never written to the `users` table (only its hash is),
 *   never logged, never audited, and never embedded in an exception message.
 * * The notification is queued with an encrypted payload, so a readable
 *   `jobs.payload` column can never leak the link.
 * * Delivery is refused (rather than silently written to a log) whenever the
 *   configured transport cannot actually deliver. In local/testing the
 *   `log` transport stays allowed so the project remains runnable without SMTP.
 */
class EmailVerificationService implements EmailVerificationServiceInterface
{
    /**
     * Cost-equalising secret.
     *
     * When the account or its token is absent we still perform one real hash
     * comparison so that "no such account" costs the same as "wrong token" and
     * the endpoint cannot be probed for account existence by timing alone.
     *
     * The constant is not a credential: it is never issued to anyone and only
     * exists so the comparison has *something* to compare against.
     */
    private const TIMING_EQUALISING_DUMMY_SECRET = 'email-verification-timing-equaliser';

    private const TOKEN_BYTES = 64;

    private const TOKEN_TTL_HOURS = 24;

    /**
     * Lazily built, per-request cache of the dummy hash.
     *
     * Generated with the application's own hasher (and therefore its own
     * configured cost factor) rather than hardcoded, so the burn rate always
     * matches a genuine token comparison.
     */
    private ?string $timingEqualisingHash = null;

    public function __construct(
        private readonly MailConfigurationValidatorInterface $mailConfiguration,
    ) {}

    public function issue(User $user): string
    {
        $rawToken = Str::random(self::TOKEN_BYTES);

        $user->forceFill([
            // Only the hash is persisted. `Str::random` uses a CSPRNG, and the
            // raw value now exists solely in this scope and in the outbound
            // notification.
            'email_verification_token' => Hash::make($rawToken),
            'email_verification_token_expires_at' => now()->addHours(self::TOKEN_TTL_HOURS),
        ])->saveQuietly();

        return $rawToken;
    }

    public function issueAndDispatch(User $user): bool
    {
        $rawToken = $this->issue($user);

        return $this->dispatch($user, $rawToken);
    }

    public function resendForEmail(string $email): bool
    {
        // Public endpoint: the account is looked up by an untrusted address, so
        // the tenant scope must be bypassed explicitly. The response to the
        // caller is uniform regardless of what happens here.
        $user = User::withoutGlobalScope(ChurchScope::class)
            ->where('email', $email)
            ->first();

        if ($user === null) {
            Log::info('email_verification', [
                'event' => 'verification_resend_skipped',
                'reason' => 'unknown_address',
            ]);

            return false;
        }

        if ($user->email_verified_at !== null) {
            Log::info('email_verification', [
                'event' => 'verification_resend_skipped',
                'user_id' => $user->id,
                'reason' => 'already_verified',
            ]);

            return false;
        }

        return $this->issueAndDispatch($user);
    }

    public function verify(string $email, string $rawToken): EmailVerificationOutcome
    {
        $user = User::withoutGlobalScope(ChurchScope::class)
            ->where('email', $email)
            ->first();

        if ($user === null) {
            // Still burn a hash comparison so "no such account" is not
            // distinguishable from "wrong token" by response time.
            Hash::check($rawToken, $this->timingEqualisingHash());

            Log::info('email_verification', [
                'event' => 'verification_rejected',
                'reason' => 'unknown_address',
            ]);

            return EmailVerificationOutcome::Failed;
        }

        if ($user->email_verified_at !== null) {
            // Logged as a reason category only: an unauthenticated caller must
            // not be able to learn that this address is already verified.
            Log::info('email_verification', [
                'event' => 'verification_rejected',
                'user_id' => $user->id,
                'reason' => 'already_verified',
            ]);

            return EmailVerificationOutcome::Failed;
        }

        $storedHash = $user->email_verification_token;
        $expiresAt = $user->email_verification_token_expires_at;

        if (! is_string($storedHash) || $storedHash === '' || ! $expiresAt instanceof Carbon) {
            Hash::check($rawToken, $this->timingEqualisingHash());

            Log::info('email_verification', [
                'event' => 'verification_rejected',
                'user_id' => $user->id,
                'reason' => 'no_token_issued',
            ]);

            return EmailVerificationOutcome::Failed;
        }

        if ($expiresAt->isPast()) {
            Log::info('email_verification', [
                'event' => 'verification_rejected',
                'user_id' => $user->id,
                'reason' => 'expired',
            ]);

            return EmailVerificationOutcome::Failed;
        }

        if (! Hash::check($rawToken, $storedHash)) {
            Log::warning('email_verification', [
                'event' => 'verification_rejected',
                'user_id' => $user->id,
                'reason' => 'token_mismatch',
            ]);

            return EmailVerificationOutcome::Failed;
        }

        // Single use: consuming the token nulls the stored hash, so a replay of
        // the same link cannot succeed a second time.
        $consumed = User::withoutGlobalScope(ChurchScope::class)
            ->whereKey($user->getKey())
            ->where('email_verified_at', null)
            ->where('email_verification_token', $storedHash)
            ->update([
                'email_verified_at' => now(),
                'email_verification_token' => null,
                'email_verification_token_expires_at' => null,
            ]);

        if ($consumed === 0) {
            // Lost a race with a concurrent verification, or the row changed
            // underneath us. Either way the outcome is a plain failure.
            Log::warning('email_verification', [
                'event' => 'verification_rejected',
                'user_id' => $user->id,
                'reason' => 'concurrent_consumption',
            ]);

            return EmailVerificationOutcome::Failed;
        }

        Log::info('email_verification', [
            'event' => 'verification_succeeded',
            'user_id' => $user->id,
        ]);

        return EmailVerificationOutcome::Verified;
    }

    /**
     * A real hash to burn a comparison against when there is nothing to check.
     */
    private function timingEqualisingHash(): string
    {
        if ($this->timingEqualisingHash === null) {
            $this->timingEqualisingHash = Hash::make(self::TIMING_EQUALISING_DUMMY_SECRET);
        }

        return $this->timingEqualisingHash;
    }

    /**
     * Hand the link to the mail transport, refusing to do so when the
     * transport would persist the message somewhere it does not belong.
     */
    private function dispatch(User $user, string $rawToken): bool
    {
        if (! $this->mailConfiguration->isVerificationDeliveryConfigured()) {
            // Never hand a token to a transport that will write it to a log.
            Log::error('email_verification', [
                'event' => 'verification_dispatch_refused',
                'reason' => 'transport_cannot_deliver',
                'problems' => $this->mailConfiguration->problems(),
            ]);

            return false;
        }

        $frontendUrl = config('app.frontend_url');
        $verificationUrl = sprintf(
            '%s/verify-email?token=%s&email=%s',
            is_string($frontendUrl) ? $frontendUrl : '',
            urlencode($rawToken),
            urlencode($user->email),
        );

        // The notification is ShouldQueue + ShouldBeEncrypted, so the raw link
        // only ever exists inside an encrypted `jobs.payload` column — never in
        // a readable log, and never in a plaintext database row.
        $user->notify(new VerifyEmailNotification($user, $verificationUrl));

        Log::info('email_verification', [
            'event' => 'verification_dispatched',
            'user_id' => $user->id,
        ]);

        return true;
    }
}
