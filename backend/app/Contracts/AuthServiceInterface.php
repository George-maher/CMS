<?php

namespace App\Contracts;

interface AuthServiceInterface
{
    public function login(array $credentials): array;
    public function platformLogin(array $credentials): array;
    public function logout($user): void;
    public function register(array $data): array;
    public function getAuthenticatedUser($user): array;
    public function forgotPassword(array $data): array;
    public function resetPassword(array $data): array;
}
