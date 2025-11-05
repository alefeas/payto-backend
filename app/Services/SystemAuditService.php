<?php

namespace App\Services;

use App\Repositories\SystemAuditLogRepository;
use Illuminate\Support\Facades\Request;

class SystemAuditService
{
    public function __construct(private SystemAuditLogRepository $repository)
    {
    }

    /**
     * Log a global/system-level event (not tied to company)
     */
    public function log(
        ?string $userId,
        string $action,
        ?string $description = null,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = []
    ): void {
        $this->repository->create([
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
}