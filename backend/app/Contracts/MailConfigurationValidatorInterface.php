<?php

namespace App\Contracts;

use App\Exceptions\InsecureMailConfigurationException;

/**
 * Guards the outbound mail transport.
 *
 * The system sends no email at all through a non-delivering transport in a
 * real environment, because the messages it would "send" (email verification
 * links) carry account-verification tokens. Laravel's `log` transport writes
 * the rendered message — token included — to the application log, which turns
 * log read access into account takeover.
 */
interface MailConfigurationValidatorInterface
{
    /**
     * Transports that do not actually deliver mail.
     *
     * `log`     — writes the full rendered message to the application log.
     * `array`   — keeps messages in a PHP array (lost on request end).
     * `null`    — discards everything.
     * `fail`    — throws on every send.
     *
     * @return array<int, string>
     */
    public function nonDeliveringDrivers(): array;

    /**
     * Whether this environment is expected to deliver real email.
     *
     * Local development and the test suite are exempt so that the project stays
     * trivially runnable without SMTP credentials.
     */
    public function requiresRealDelivery(): bool;

    /**
     * Whether the current process must refuse to start when the transport is
     * unusable: the web process and queue workers, but not ordinary CLI tooling.
     */
    public function shouldEnforceAtBoot(): bool;

    /**
     * Whether email verification links can actually be delivered right now.
     */
    public function isVerificationDeliveryConfigured(): bool;

    /**
     * Human-readable, value-free descriptions of what is wrong.
     *
     * @return array<int, string>
     */
    public function problems(): array;

    /**
     * @throws InsecureMailConfigurationException when the configuration is unusable.
     */
    public function assertVerificationDeliveryConfigured(): void;
}
