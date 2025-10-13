<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Request;

class AuditService
{
    public function log(
        string $companyId,
        string $userId,
        string $action,
        string $description,
        string $entityType = null,
        string $entityId = null,
        array $metadata = []
    ): void {
        AuditLog::create([
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

    public function getCompanyLogs(string $companyId, int $perPage = 50): array
    {
        $logs = AuditLog::where('company_id', $companyId)
            ->with('user:id,first_name,last_name,email')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return [
            'data' => $logs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'companyId' => $log->company_id,
                    'userId' => $log->user_id,
                    'action' => $log->action,
                    'entityType' => $log->entity_type,
                    'entityId' => $log->entity_id,
                    'description' => $log->description,
                    'metadata' => $log->metadata,
                    'ipAddress' => $log->ip_address,
                    'userAgent' => $log->user_agent,
                    'createdAt' => $log->created_at->toIso8601String(),
                    'user' => [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                        'email' => $log->user->email,
                    ]
                ];
            })->toArray(),
            'pagination' => [
                'total' => $logs->total(),
                'perPage' => $logs->perPage(),
                'currentPage' => $logs->currentPage(),
                'lastPage' => $logs->lastPage(),
            ]
        ];
    }
}
