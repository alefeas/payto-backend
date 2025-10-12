<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
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
            
            return $this->success([
                'user' => $result['user'],
                'token' => $result['token'],
            ], 'User registered successfully', 201);
        } catch (\Exception $e) {
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
            return $this->success(['user' => $request->user()]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
