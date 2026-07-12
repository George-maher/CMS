<?php

namespace App\Contracts;

use App\Models\User;

interface AuthServiceInterface
{
    /** @param array<string, mixed> $credentials */
    /** @return array{user: User, token: string, token_type: string, application_status?: string|null} */
    public function login(array $credentials): array;

    /** @param array<string, mixed> $credentials */
    /** @return array{user: User, token: string, token_type: string} */
    public function platformLogin(array $credentials): array;

    public function logout(User $user): void;

    /** @param array<string, mixed> $data */
    /** @return array{user: User, message: string} */
    public function register(array $data): array;

    /** @return array{user: User} */
    public function getAuthenticatedUser(User $user): array;

    /** @param array<string, mixed> $data */
    /** @return array{message: string} */
    public function forgotPassword(array $data): array;

    /** @param array<string, mixed> $data */
    /** @return array{message: string} */
    public function resetPassword(array $data): array;
}
