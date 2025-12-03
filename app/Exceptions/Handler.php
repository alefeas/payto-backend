<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        if ($request->is('api/*')) {
            return $this->handleApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    private function addCorsHeaders($response)
    {
        return $response
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin')
            ->header('Access-Control-Max-Age', '86400');
    }

    private function handleApiException($request, Throwable $e)
    {
        if ($e instanceof ValidationException) {
            return $this->addCorsHeaders(response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422));
        }

        if ($e instanceof AuthenticationException) {
            return $this->addCorsHeaders(response()->json([
                'success' => false,
                'message' => 'No autenticado',
            ], 401));
        }

        if ($e instanceof AuthorizationException) {
            return $this->addCorsHeaders(response()->json([
                'success' => false,
                'message' => 'No tienes permisos para realizar esta acción',
            ], 403));
        }

        if ($e instanceof UnauthorizedException) {
            return $this->addCorsHeaders(response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 401));
        }

        if ($e instanceof ForbiddenException) {
            return $this->addCorsHeaders(response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403));
        }

        if ($e instanceof BadRequestException) {
            return $this->addCorsHeaders(response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400));
        }

        if ($e instanceof NotFoundException) {
            return $this->addCorsHeaders(response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404));
        }

        if ($e instanceof ModelNotFoundException) {
            return $this->addCorsHeaders(response()->json([
                'success' => false,
                'message' => 'Resource not found',
            ], 404));
        }

        if ($e instanceof NotFoundHttpException) {
            return $this->addCorsHeaders(response()->json([
                'success' => false,
                'message' => 'Endpoint not found',
            ], 404));
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return $this->addCorsHeaders(response()->json([
                'success' => false,
                'message' => 'Method not allowed',
            ], 405));
        }

        $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

        return $this->addCorsHeaders(response()->json([
            'success' => false,
            'message' => config('app.debug') ? $e->getMessage() : 'Server error',
            'trace' => config('app.debug') ? $e->getTrace() : null,
        ], $statusCode));
    }
}
