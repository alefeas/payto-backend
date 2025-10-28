<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Interfaces\AuthServiceInterface;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponse;

    private AuthServiceInterface $authService;

    public function __construct(AuthServiceInterface $authService)
    {
        $this->authService = $authService;
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            \Log::info('Register request data:', $request->all());
            $result = $this->authService->register($request->validated());
            
            return $this->success($result, 'Código de verificación enviado', 201);
        } catch (\Exception $e) {
            \Log::error('Register error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error($e->getMessage(), 500);
        }
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login($request->validated());
            
            return $this->success([
                'user' => $result['user'],
                'token' => $result['token'],
            ], 'Login successful');
        } catch (\Exception $e) {
            return $this->unauthorized('Invalid credentials');
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();
            
            if (!$token) {
                return $this->unauthorized('Token not provided');
            }

            $this->authService->logout($token);
            
            return $this->success(null, 'Logged out successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function me(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $token = $request->bearerToken();
            $userData = $this->authService->getCurrentUser($token);
            return $this->success($userData);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        try {
            $user = $this->authService->updateProfile(
                $request->user()->id,
                $request->validated()
            );
            return $this->success($user, 'Perfil actualizado correctamente');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function verifyCode(Request $request): JsonResponse
    {
        try {
            $email = $request->input('email');
            $code = $request->input('code');
            
            if (!$email || !$code) {
                return $this->error('Email y código son requeridos', 400);
            }

            $result = $this->authService->verifyRegistrationCode($email, $code);
            
            return $this->success($result, 'Cuenta creada exitosamente');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    public function resendCode(Request $request): JsonResponse
    {
        try {
            $email = $request->input('email');
            
            if (!$email) {
                return $this->error('Email no proporcionado', 400);
            }

            $this->authService->resendVerificationCode($email);
            
            return $this->success(null, 'Nuevo código enviado a tu email');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    public function requestPasswordReset(Request $request): JsonResponse
    {
        try {
            $email = $request->input('email');
            
            if (!$email) {
                return $this->error('Email no proporcionado', 400);
            }

            $this->authService->requestPasswordReset($email);
            
            return $this->success(null, 'Si el email existe, recibirás instrucciones para recuperar tu contraseña');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $this->authService->resetPassword($validated['token'], $validated['password']);
            
            return $this->success(null, 'Contraseña restablecida correctamente');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 400);
        }
    }
}
