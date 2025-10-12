<?php

namespace App\Services;

use App\Exceptions\UnauthorizedException;
use App\Interfaces\AuthServiceInterface;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService implements AuthServiceInterface
{
    public function register(array $data): array
    {
        $user = User::create([
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'first_name' => $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? '',
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender' => $data['gender'] ?? null,
            'country' => $data['country'] ?? 'Argentina',
            'province' => $data['province'] ?? null,
            'city' => $data['city'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'street' => $data['street'] ?? null,
            'street_number' => $data['street_number'] ?? null,
            'floor' => $data['floor'] ?? null,
            'apartment' => $data['apartment'] ?? null,
        ]);

        $tokenName = 'auth_token_' . now()->format('Y-m-d_H:i:s');
        $token = $user->createToken($tokenName)->plainTextToken;

        return [
            'user' => $this->formatUserData($user),
            'token' => $token,
        ];
    }

    public function login(array $credentials): array
    {
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new UnauthorizedException('Credenciales inválidas');
        }

        // Revocar tokens anteriores por seguridad
        $user->tokens()->delete();

        $tokenName = 'auth_token_' . now()->format('Y-m-d_H:i:s');
        $token = $user->createToken($tokenName)->plainTextToken;

        return [
            'user' => $this->formatUserData($user),
            'token' => $token,
        ];
    }

    public function logout(string $token): bool
    {
        $user = User::whereHas('tokens', function ($query) use ($token) {
            $query->where('token', hash('sha256', explode('|', $token)[1]));
        })->first();

        if ($user) {
            $user->tokens()->delete();
            return true;
        }

        return false;
    }

    public function getCurrentUser(string $token): ?array
    {
        $user = User::whereHas('tokens', function ($query) use ($token) {
            $query->where('token', hash('sha256', explode('|', $token)[1]));
        })->first();

        return $user ? $this->formatUserData($user) : null;
    }

    public function updateProfile(string $userId, array $data): array
    {
        $user = User::findOrFail($userId);
        $user->update($data);
        return $this->formatUserData($user->fresh());
    }

    private function formatUserData(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'name' => trim($user->first_name . ' ' . $user->last_name) ?: 'User',
            'phone' => $user->phone,
            'avatarUrl' => $user->avatar_url,
            'emailVerified' => $user->email_verified,
            'dateOfBirth' => $user->date_of_birth?->format('Y-m-d'),
            'gender' => $user->gender,
            'country' => $user->country,
            'province' => $user->province,
            'city' => $user->city,
            'postalCode' => $user->postal_code,
            'street' => $user->street,
            'streetNumber' => $user->street_number,
            'floor' => $user->floor,
            'apartment' => $user->apartment,
            'bio' => '',
            'timezone' => '',
        ];
    }
}
