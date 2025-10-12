<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Exceptions\UnauthorizedException;

class CheckCompanyRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $companyId = $request->route('company') ?? $request->input('company_id');
        $user = $request->user();

        if (!$companyId) {
            throw new UnauthorizedException('ID de empresa requerido');
        }

        $member = $user->companyMembers()->where('company_id', $companyId)->first();

        if (!$member || !in_array($member->role, $roles)) {
            throw new UnauthorizedException('No tienes permisos suficientes');
        }

        $request->merge(['user_role' => $member->role]);

        return $next($request);
    }
}
