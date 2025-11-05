<?php

namespace App\Repositories;

use App\Models\SystemAuditLog;
use Illuminate\Pagination\LengthAwarePaginator;

class SystemAuditLogRepository
{
    /**
     * Create a new system audit log entry
     */
    public function create(array $data): SystemAuditLog
    {
        return SystemAuditLog::create($data);
    }

    /**
     * Get logs with optional filters (not exposed to frontend)
     */
    public function getLogs(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = SystemAuditLog::query();

        if (!empty($filters['action'])) {
            $query->where('action', 'like', '%' . $filters['action'] . '%');
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (!empty($filters['entity_id'])) {
            $query->where('entity_id', $filters['entity_id']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }
}