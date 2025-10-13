<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use ApiResponse;

    private AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    public function index(Request $request, string $companyId): JsonResponse
    {
        $perPage = $request->input('per_page', 50);
        $logs = $this->auditService->getCompanyLogs($companyId, $perPage);
        
        return $this->success($logs, 'Logs de auditoría obtenidos exitosamente');
    }
}
