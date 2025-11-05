<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Traits\ApiResponse;
use App\Http\Requests\AuditLogRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    use ApiResponse;

    private AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Get company audit logs with filtering
     */
    public function index(AuditLogRequest $request, string $companyId): JsonResponse
    {
        $validated = $request->validated();
        $perPage = $validated['per_page'] ?? 50;
        
        $filters = [
            'action' => $validated['action'] ?? null,
            'entity_type' => $validated['entity_type'] ?? null,
            'entity_id' => $validated['entity_id'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'ip_address' => $validated['ip_address'] ?? null,
            'description' => $validated['description'] ?? null,
        ];

        // Remove null values
        $filters = array_filter($filters);
        
        $logs = $this->auditService->getCompanyLogs($companyId, $filters, $perPage);
        
        return $this->success($logs, 'Logs de auditoría obtenidos exitosamente');
    }

    /**
     * Get audit statistics for a company
     */
    public function stats(AuditLogRequest $request, string $companyId): JsonResponse
    {
        $validated = $request->validated();
        
        $filters = [
            'action' => $validated['action'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
        ];

        // Remove null values
        $filters = array_filter($filters);
        
        $stats = $this->auditService->getCompanyStats($companyId, $filters);
        
        return $this->success($stats, 'Estadísticas de auditoría obtenidas exitosamente');
    }

    /**
     * Get audit logs for a specific entity
     */
    public function entityLogs(Request $request, string $companyId, string $entityType, string $entityId): JsonResponse
    {
        $perPage = $request->input('per_page', 50);
        $logs = $this->auditService->getEntityLogs($companyId, $entityType, $entityId, $perPage);
        
        return $this->success($logs, 'Logs de auditoría de entidad obtenidos exitosamente');
    }

    /**
     * Get audit logs for a specific user
     */
    public function userLogs(Request $request, string $companyId, string $userId): JsonResponse
    {
        $perPage = $request->input('per_page', 50);
        $logs = $this->auditService->getUserLogs($companyId, $userId, $perPage);
        
        return $this->success($logs, 'Logs de auditoría de usuario obtenidos exitosamente');
    }

    /**
     * Get recent audit activities
     */
    public function recent(Request $request, string $companyId): JsonResponse
    {
        $limit = $request->input('limit', 10);
        $activities = $this->auditService->getRecentActivities($companyId, $limit);
        
        return $this->success($activities, 'Actividades recientes obtenidas exitosamente');
    }

    /**
     * Get audit trail for specific entity
     */
    public function trail(Request $request, string $companyId, string $entityType, string $entityId): JsonResponse
    {
        $trail = $this->auditService->getAuditTrail($companyId, $entityType, $entityId);
        
        return $this->success($trail, 'Rastro de auditoría obtenido exitosamente');
    }

    /**
     * Export audit logs to CSV
     */
    public function export(AuditLogRequest $request, string $companyId): StreamedResponse
    {
        $validated = $request->validated();
        
        $filters = [
            'action' => $validated['action'] ?? null,
            'entity_type' => $validated['entity_type'] ?? null,
            'entity_id' => $validated['entity_id'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'ip_address' => $validated['ip_address'] ?? null,
            'description' => $validated['description'] ?? null,
        ];

        // Remove null values
        $filters = array_filter($filters);
        
        $csvContent = $this->auditService->exportToCsv($companyId, $filters);
        
        $filename = 'audit_logs_' . date('Y-m-d_H-i-s') . '.csv';
        
        return response()->stream(function () use ($csvContent) {
            echo $csvContent;
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
