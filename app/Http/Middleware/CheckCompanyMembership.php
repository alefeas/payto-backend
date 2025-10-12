<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Exceptions\UnauthorizedException;

class CheckCompanyMembership
{
    public function handle(Request $request, Closure $next)
    {
        $companyId = $request->route('company') ?? $request->input('company_id');
        
        if (!$companyId) {
            return $next($request);
        }

        $user = $request->user();
        
        if (!$user->companyMembers()->where('company_id', $companyId)->exists()) {
            throw new UnauthorizedException('No tienes acceso a esta empresa');
        }

        return $next($request);
    }
}
