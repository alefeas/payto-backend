<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\CompanyAfipCertificate;

class ValidateAfipCertificate
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // Obtener company_id de la ruta o parámetros
        $companyId = $request->route('company_id') ?? $request->route('companyId') ?? $request->route('company') ?? $request->route('id') ?? $request->input('company_id');
        
        if (!$companyId) {
            return response()->json([
                'success' => false,
                'message' => 'Company ID requerido para validar certificado AFIP'
            ], 400);
        }

        // Verificar si existe un certificado AFIP activo para la empresa
        $certificate = CompanyAfipCertificate::where('company_id', $companyId)
            ->where('is_active', true)
            ->first();

        if (!$certificate) {
            return response()->json([
                'success' => false,
                'message' => 'Certificado AFIP requerido. Debe configurar un certificado AFIP activo para usar esta funcionalidad.',
                'error_code' => 'AFIP_CERTIFICATE_REQUIRED',
                'redirect_url' => "/company/{$companyId}/verify"
            ], 403);
        }

        // Verificar si el certificado no ha expirado
        if ($certificate->expires_at && $certificate->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'El certificado AFIP ha expirado. Debe renovar su certificado para continuar.',
                'error_code' => 'AFIP_CERTIFICATE_EXPIRED',
                'redirect_url' => "/company/{$companyId}/verify"
            ], 403);
        }

        // Agregar información del certificado al request para uso posterior
        $request->merge(['afip_certificate' => $certificate]);

        return $next($request);
    }
}