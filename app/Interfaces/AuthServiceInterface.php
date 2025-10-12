<?php

namespace App\Interfaces;

interface AuthServiceInterface
{
    public function register(array $data): array;
    public function login(array $credentials): array;
    public function logout(string $token): bool;
    public function getCurrentUser(string $token): ?array;
    public function updateProfile(string $userId, array $data): array;
}
