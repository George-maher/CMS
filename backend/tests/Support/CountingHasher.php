<?php

namespace Tests\Support;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\HashManager;

/**
 * Counts password-hashing operations while delegating every call to the real
 * hasher, so application behaviour is unchanged and only the number of bcrypt
 * operations is recorded.
 *
 * It implements the Hasher contract because the framework type-hints that
 * contract when it builds an EloquentUserProvider.
 *
 * Used by the login/platform-login timing-parity tests (F-01, F-03).
 */
final class CountingHasher implements Hasher
{
    public int $checks = 0;

    public int $makes = 0;

    public function __construct(private readonly HashManager $real) {}

    /**
     * @param  string  $value
     * @param  array<string, mixed>  $options
     */
    public function make(#[\SensitiveParameter] $value, array $options = []): string
    {
        $this->makes++;

        return $this->real->make($value, $options);
    }

    /**
     * @param  string  $value
     * @param  string|null  $hashedValue
     * @param  array<string, mixed>  $options
     */
    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        $this->checks++;

        return $this->real->check($value, $hashedValue, $options);
    }

    /**
     * @param  string  $hashedValue
     * @param  array<string, mixed>  $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->real->needsRehash($hashedValue, $options);
    }

    /**
     * @param  string  $hashedValue
     * @return array<string, mixed>
     */
    public function info($hashedValue): array
    {
        return $this->real->info($hashedValue);
    }

    /**
     * Anything else the framework asks of the hasher (isHashed,
     * verifyConfiguration, driver, ...) is forwarded uncounted.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->real->{$method}(...$arguments);
    }
}
