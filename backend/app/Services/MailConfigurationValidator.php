<?php

namespace App\Services;

use App\Contracts\MailConfigurationValidatorInterface;
use App\Exceptions\InsecureMailConfigurationException;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves whether the configured mail transport can safely be used to deliver
 * email verification links.
 *
 * This is deliberately the *only* place that knows about "is this mailer real",
 * so the boot-time guard, the dispatch path and the tests can never disagree.
 *
 * No credential values are ever read into, or emitted from, this class — only
 * driver names and boolean presence, so it is safe to log its output.
 */
class MailConfigurationValidator implements MailConfigurationValidatorInterface
{
    /** @var array<int, string> */
    private const NON_DELIVERING_DRIVERS = ['log', 'array', 'null', 'fail'];

    /** @var array<int, string> */
    private const EXEMPT_ENVIRONMENTS = ['local', 'testing'];

    /**
     * Console commands that process queued jobs.
     *
     * A worker is a process that can render a verification email, so it is held
     * to the same standard as the web process. Every other console command
     * (migrations, `config:cache`, `route:list`, tests) is exempt so that a
     * deploy pipeline can still run while mail configuration is being fixed.
     *
     * @var array<int, string>
     */
    private const JOB_PROCESSING_COMMANDS = [
        'queue:work',
        'queue:listen',
    ];

    public function __construct(
        private readonly Application $app,
    ) {}

    /** @return array<int, string> */
    public function nonDeliveringDrivers(): array
    {
        return self::NON_DELIVERING_DRIVERS;
    }

    public function requiresRealDelivery(): bool
    {
        return ! in_array($this->app->environment(), self::EXEMPT_ENVIRONMENTS, true);
    }

    public function shouldEnforceAtBoot(): bool
    {
        if (! $this->requiresRealDelivery()) {
            return false;
        }

        if (! $this->app->runningInConsole()) {
            return true;
        }

        return in_array($this->consoleCommandName(), self::JOB_PROCESSING_COMMANDS, true);
    }

    public function problems(): array
    {
        if (! $this->requiresRealDelivery()) {
            return [];
        }

        /** @var array<int, string> $problems */
        $problems = [];

        $driver = $this->driver();

        if ($driver === null) {
            $problems[] = 'MAIL_MAILER is not set';
        } elseif (in_array($driver, self::NON_DELIVERING_DRIVERS, true)) {
            $problems[] = sprintf(
                'MAIL_MAILER is set to the non-delivering transport "%s"',
                $driver,
            );
        }

        $from = config('mail.from.address');
        if (! is_string($from) || trim($from) === '') {
            $problems[] = 'MAIL_FROM_ADDRESS is not set';
        }

        return $problems;
    }

    public function isVerificationDeliveryConfigured(): bool
    {
        return $this->problems() === [];
    }

    public function assertVerificationDeliveryConfigured(): void
    {
        $problems = $this->problems();

        if ($problems !== []) {
            throw new InsecureMailConfigurationException($problems);
        }
    }

    private function driver(): ?string
    {
        $mailer = config('mail.default');

        return is_string($mailer) && $mailer !== '' ? $mailer : null;
    }

    /**
     * The Artisan command currently executing, or an empty string.
     *
     * Read from argv because the console kernel does not expose the resolved
     * command name before dispatch.
     */
    private function consoleCommandName(): string
    {
        /** @var array<int, mixed>|false $argv */
        $argv = $_SERVER['argv'] ?? [];

        if (! is_array($argv) || ! isset($argv[1]) || ! is_string($argv[1])) {
            return '';
        }

        return $argv[1];
    }
}
