<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class Cors
{
    public function handle(Request $request, Closure $next)
    {
        $allowedOrigins = [
            'https://payto-frontend.vercel.app',
            'https://payto-frontend-git-main-alefeas-projects.vercel.app',
            'http://localhost:3000',
        ];

        $origin = $request->header('Origin');
        
        // Check if origin matches allowed origins or is a Vercel preview URL
        if (in_array($origin, $allowedOrigins) || 
            ($origin && preg_match('/^https:\/\/.*\.vercel\.app$/', $origin))) {
            
            $response = $next($request);
            
            // BinaryFileResponse uses headers->set() instead of header()
            if ($response instanceof BinaryFileResponse) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin');
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Access-Control-Max-Age', '86400');
            } else {
                $response->header('Access-Control-Allow-Origin', $origin)
                    ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin')
                    ->header('Access-Control-Allow-Credentials', 'true')
                    ->header('Access-Control-Max-Age', '86400');
            }
            
            return $response;
        }

        return $next($request);
    }
}
