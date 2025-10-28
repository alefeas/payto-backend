<?php

namespace App\Services;

use App\Exceptions\UnauthorizedException;
use App\Interfaces\AuthServiceInterface;
use App\Mail\ResetPassword;
use App\Mail\VerificationCode;
use App\Models\PasswordReset;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthService implements AuthServiceInterface
{
    public function register(array $data): array
    {
        // Verificar email único
        if (User::where('email', $data['email'])->exists()) {
            throw new \Exception('El email ya está registrado');
        }

        // Eliminar TODOS los registros pendientes para este email
        // Esto permite que el verdadero dueño del email siempre pueda registrarse
        PendingRegistration::where('email', $data['email'])
            ->whereNull('verified_at')
            ->delete();

        // Generar código de 6 dígitos
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Crear nuevo registro pendiente
        PendingRegistration::create([
            'email' => $data['email'],
            'code' => $code,
            'user_data' => $data,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        // Enviar código por email
        $userName = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?: 'Usuario';
        Mail::to($data['email'])->send(new VerificationCode($userName, $code));

        return [
            'message' => 'Código de verificación enviado a tu email',
            'email' => $data['email'],
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

    public function verifyRegistrationCode(string $email, string $code): array
    {
        $pending = PendingRegistration::where('email', $email)
            ->whereNull('verified_at')
            ->first();

        if (!$pending) {
            throw new \Exception('No se encontró un registro pendiente para este email');
        }

        if ($pending->attempts >= 5) {
            throw new \Exception('Demasiados intentos fallidos. Solicitá un nuevo código.');
        }

        if ($pending->code !== $code) {
            $pending->incrementAttempts();
            throw new \Exception('Código incorrecto. Intentos restantes: ' . (5 - $pending->attempts));
        }

        if ($pending->isExpired()) {
            throw new \Exception('El código ha expirado. Solicitá uno nuevo.');
        }

        // Código correcto, crear usuario
        $userData = $pending->user_data ?? [];
        
        if (empty($userData)) {
            throw new \Exception('Datos de registro inválidos. Solicitá un nuevo código.');
        }
        
        $user = User::create([
            'email' => $userData['email'],
            'password' => Hash::make($userData['password']),
            'first_name' => $userData['first_name'] ?? '',
            'last_name' => $userData['last_name'] ?? '',
            'phone' => $userData['phone'] ?? null,
            'date_of_birth' => $userData['date_of_birth'] ?? null,
            'gender' => $userData['gender'] ?? null,
            'country' => $userData['country'] ?? 'Argentina',
            'province' => $userData['province'] ?? null,
            'city' => $userData['city'] ?? null,
            'postal_code' => $userData['postal_code'] ?? null,
            'street' => $userData['street'] ?? null,
            'street_number' => $userData['street_number'] ?? null,
            'floor' => $userData['floor'] ?? null,
            'apartment' => $userData['apartment'] ?? null,
            'email_verified' => true,
        ]);

        // Marcar como verificado y eliminar
        $pending->update(['verified_at' => now()]);
        $pending->delete();

        // Crear token
        $tokenName = 'auth_token_' . now()->format('Y-m-d_H:i:s');
        $token = $user->createToken($tokenName)->plainTextToken;

        return [
            'user' => $this->formatUserData($user),
            'token' => $token,
        ];
    }

    public function resendVerificationCode(string $email): bool
    {
        $pending = PendingRegistration::where('email', $email)
            ->whereNull('verified_at')
            ->first();

        if (!$pending) {
            throw new \Exception('No se encontró un registro pendiente para este email');
        }

        // Generar nuevo código
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        $pending->update([
            'code' => $code,
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        // Enviar nuevo código
        $userData = $pending->user_data ?? [];
        $userName = trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? '')) ?: 'Usuario';
        Mail::to($email)->send(new VerificationCode($userName, $code));

        return true;
    }

    public function requestPasswordReset(string $email): bool
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            throw new \Exception('No existe una cuenta con este email');
        }

        // Invalidar tokens anteriores
        PasswordReset::where('email', $email)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token = Str::random(64);
        $passwordReset = PasswordReset::create([
            'email' => $email,
            'token' => $token,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
        ]);

        $resetUrl = config('app.frontend_url') . '/reset-password?token=' . $token;
        $userName = trim($user->first_name . ' ' . $user->last_name) ?: 'Usuario';

        Mail::to($user->email)->send(new ResetPassword($userName, $resetUrl));

        return true;
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        $passwordReset = PasswordReset::where('token', $token)
            ->whereNull('used_at')
            ->first();

        if (!$passwordReset || $passwordReset->isExpired()) {
            throw new \Exception('Token de recuperación inválido o expirado');
        }

        $user = User::where('email', $passwordReset->email)->first();

        if (!$user) {
            throw new \Exception('Usuario no encontrado');
        }

        DB::transaction(function () use ($user, $newPassword, $passwordReset) {
            $user->update(['password' => Hash::make($newPassword)]);
            $passwordReset->update(['used_at' => now()]);
            // Revocar todos los tokens por seguridad
            $user->tokens()->delete();
        });

        return true;
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
