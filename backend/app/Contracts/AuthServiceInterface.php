<?php

namespace App\Contracts;

interface AuthServiceInterface
{
    /** @param array<string, mixed> $credentials */
    /** @return array<string, mixed> */
    public function login(array $credentials): array;

    /** @param array<string, mixed> $credentials */
    /** @return array<string, mixed> */
    public function platformLogin(array $credentials): array;

    public function logout(\App\Models\User $user): void;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function register(array $data): array;

    /** @return array<string, mixed> */
    public function getAuthenticatedUser(\App\Models\User $user): array;

    /**
     * Submit a password-reset *request*.
     *
     * This never issues a reset token: the new password is only set after the
     * church admin approves and sets it through the in-app workflow
     * (PasswordResetRequestService). There is deliberately no token-based
     * `resetPassword()` on this contract.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function forgotPassword(array $data): array;
}
