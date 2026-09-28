<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when the application is running in an environment that must deliver
 * real email, but the configured mail transport cannot do so.
 *
 * This exists so that a missing or placeholder mail configuration fails the
 * deployment loudly, at boot, instead of silently degrading to Laravel's
 * `log` transport — which would write email verification links (and therefore
 * account-verification tokens) into the application log file.
 *
 * The message intentionally names no credential values: it is surfaced to logs
 * and to operators, and must never be able to leak a secret.
 */
class InsecureMailConfigurationException extends RuntimeException
{
    /**
     * @param  array<int, string>  $reasons
     */
    public function __construct(private readonly array $reasons)
    {
        parent::__construct(sprintf(
            'Refusing to start: outbound email is not configured for a real transport. Problems: %s. '
            .'Set MAIL_MAILER to a delivering transport (e.g. smtp) and configure MAIL_FROM_ADDRESS. '
            .'The "log", "array", "null" and "fail" transports are rejected outside local/testing '
            .'because they persist message contents (including verification tokens) outside the mail channel.',
            implode('; ', $reasons),
        ));
    }

    /** @return array<int, string> */
    public function reasons(): array
    {
        return $this->reasons;
    }
}
