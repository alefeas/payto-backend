<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Repositories\AuditLogRepository;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Collection;

class AuditService
{
    private AuditLogRepository $auditLogRepository;

    public function __construct(AuditLogRepository $auditLogRepository)
    {
        $this->auditLogRepository = $auditLogRepository;
    }

    /**
     * Log an audit event
     */
    public function log(
        string $companyId,
        string $userId,
        string $action,
        string $description,
        string $entityType = null,
        string $entityId = null,
        array $metadata = []
    ): void {
        $this->auditLogRepository->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }

    /**
     * Get company logs with filtering and pagination
     */
    public function getCompanyLogs(
        string $companyId, 
        array $filters = [], 
        int $perPage = 50
    ): array {
        return $this->auditLogRepository->getCompanyLogs($companyId, $filters, $perPage);
    }

    /**
     * Get audit statistics for a company
     */
    public function getCompanyStats(string $companyId, array $filters = []): array
    {
        return $this->auditLogRepository->getCompanyStats($companyId, $filters);
    }

    /**
     * Get audit logs by entity
     */
    public function getEntityLogs(
        string $companyId, 
        string $entityType, 
        string $entityId, 
        int $perPage = 50
    ): array {
        return $this->auditLogRepository->getEntityLogs($companyId, $entityType, $entityId, $perPage);
    }

    /**
     * Get audit logs by user
     */
    public function getUserLogs(
        string $companyId, 
        string $userId, 
        int $perPage = 50
    ): array {
        return $this->auditLogRepository->getUserLogs($companyId, $userId, $perPage);
    }

    /**
     * Export audit logs to CSV
     */
    public function exportToCsv(string $companyId, array $filters = []): string
    {
        return $this->auditLogRepository->exportToCsv($companyId, $filters);
    }

    /**
     * Get recent audit activities
     */
    public function getRecentActivities(string $companyId, int $limit = 10): Collection
    {
        return $this->auditLogRepository->getRecentActivities($companyId, $limit);
    }

    /**
     * Get audit trail for specific entity
     */
    public function getAuditTrail(string $companyId, string $entityType, string $entityId): array
    {
        return $this->auditLogRepository->getAuditTrail($companyId, $entityType, $entityId);
    }
}
