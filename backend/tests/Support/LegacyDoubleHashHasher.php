<?php

namespace Tests\Support;

use Illuminate\Contracts\Hashing\Hasher;

/**
 * A deterministic, deliberately non-idempotent Hasher used to exercise the
 * legacy double-hash repair branch in AuthService.
 *
 * Why a fake hasher is required:
 *
 * bcrypt embeds a random salt, so `bcrypt($password)` computed at login time
 * can never equal a `bcrypt($password)` computed when the legacy value was
 * written. `Hash::check(Hash::make($password), $legacyStoredValue)` is
 * therefore always false for genuinely salted bcrypt double hashes, and the
 * repair branch cannot be reached with the real hasher. This double makes the
 * inner value reproducible so the branch's behaviour (repair + persist +
 * single-operation login afterwards) still has regression coverage.
 */
final class LegacyDoubleHashHasher implements Hasher
{
    private const PREFIX = 'legacy$';

    /**
     * @param  string  $value
     * @param  array<string, mixed>  $options
     */
    public function make(#[\SensitiveParameter] $value, array $options = []): string
    {
        return self::PREFIX.sha1((string) $value);
    }

    /**
     * @param  string  $value
     * @param  string|null  $hashedValue
     * @param  array<string, mixed>  $options
     */
    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        return is_string($hashedValue) && $hashedValue === $this->make($value, $options);
    }

    /**
     * @param  string  $hashedValue
     * @param  array<string, mixed>  $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return false;
    }

    /**
     * @param  string  $hashedValue
     * @return array<string, mixed>
     */
    public function info($hashedValue): array
    {
        return [];
    }

    /**
     * Called by the `hashed` cast: values in the fake's own format must be
     * treated as already hashed so the cast does not hash them a second time.
     */
    public function isHashed(#[\SensitiveParameter] $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /**
     * Called by the `hashed` cast for already-hashed values.
     */
    public function verifyConfiguration(#[\SensitiveParameter] $value): bool
    {
        return true;
    }
}
