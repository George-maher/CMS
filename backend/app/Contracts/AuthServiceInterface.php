<?php

namespace App\Contracts;

interface AuthServiceInterface
{
    public function login(array $credentials): array;
    public function platformLogin(array $credentials): array;
    public function logout(\App\Models\User $user): void;
    public function register(array $data): array;
    public function getAuthenticatedUser(\App\Models\User $user): array;
    public function forgotPassword(array $data): array;
    public function resetPassword(array $data): array;
}
