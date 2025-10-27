<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

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
            
            return $next($request)
                ->header('Access-Control-Allow-Origin', $origin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400');
        }

        return $next($request);
    }
}
