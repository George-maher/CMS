<?php

namespace App\Contracts;

use App\Models\User;

interface AuthServiceInterface
{
    /** @param array<string, mixed> $credentials */
    /** @return array<string, mixed> */
    public function login(array $credentials): array;

    /** @param array<string, mixed> $credentials */
    /** @return array<string, mixed> */
    public function platformLogin(array $credentials): array;

    public function logout(User $user): void;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function register(array $data): array;

    /** @return array<string, mixed> */
    public function getAuthenticatedUser(User $user): array;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function forgotPassword(array $data): array;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function resetPassword(array $data): array;
}
