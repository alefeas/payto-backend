<?php

namespace App\Repositories;

use App\Models\AuditLog;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;

class AuditLogRepository
{
    /**
     * Create a new audit log entry
     */
    public function create(array $data): AuditLog
    {
        return AuditLog::create($data);
    }

    /**
     * Get company logs with advanced filtering
     */
    public function getCompanyLogs(string $companyId, array $filters = [], int $perPage = 50): array
    {
        $query = $this->buildFilteredQuery($companyId, $filters);
        
        $logs = $query->with('user:id,first_name,last_name,email')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->formatPaginatedResponse($logs);
    }

    /**
     * Get audit statistics for a company
     */
    public function getCompanyStats(string $companyId, array $filters = []): array
    {
        $query = $this->buildFilteredQuery($companyId, $filters);
        
        $totalLogs = $query->count();
        $uniqueUsers = $query->distinct('user_id')->count('user_id');
        $uniqueActions = $query->distinct('action')->count('action');
        
        // Get activity by day for the last 30 days
        $activityByDay = $this->getActivityByDay($companyId, $filters);
        
        // Get top actions
        $topActions = $this->getTopActions($companyId, $filters);
        
        // Get top users
        $topUsers = $this->getTopUsers($companyId, $filters);

        return [
            'totalLogs' => $totalLogs,
            'uniqueUsers' => $uniqueUsers,
            'uniqueActions' => $uniqueActions,
            'activityByDay' => $activityByDay,
            'topActions' => $topActions,
            'topUsers' => $topUsers,
        ];
    }

    /**
     * Get audit logs for a specific entity
     */
    public function getEntityLogs(
        string $companyId, 
        string $entityType, 
        string $entityId, 
        int $perPage = 50
    ): array {
        $logs = AuditLog::where('company_id', $companyId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with('user:id,first_name,last_name,email')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->formatPaginatedResponse($logs);
    }

    /**
     * Get audit logs for a specific user
     */
    public function getUserLogs(string $companyId, string $userId, int $perPage = 50): array
    {
        $logs = AuditLog::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->with('user:id,first_name,last_name,email')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->formatPaginatedResponse($logs);
    }

    /**
     * Get recent audit activities
     */
    public function getRecentActivities(string $companyId, int $limit = 10): Collection
    {
        return AuditLog::where('company_id', $companyId)
            ->with('user:id,first_name,last_name,email')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'entityType' => $log->entity_type,
                    'entityId' => $log->entity_id,
                    'createdAt' => $log->created_at->toIso8601String(),
                    'user' => [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                        'email' => $log->user->email,
                    ]
                ];
            });
    }

    /**
     * Get audit trail for specific entity
     */
    public function getAuditTrail(string $companyId, string $entityType, string $entityId): array
    {
        $logs = AuditLog::where('company_id', $companyId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with('user:id,first_name,last_name,email')
            ->orderBy('created_at', 'asc')
            ->get();

        return [
            'entityType' => $entityType,
            'entityId' => $entityId,
            'totalChanges' => $logs->count(),
            'firstActivity' => $logs->first()?->created_at->toIso8601String(),
            'lastActivity' => $logs->last()?->created_at->toIso8601String(),
            'activities' => $logs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'metadata' => $log->metadata,
                    'ipAddress' => $log->ip_address,
                    'createdAt' => $log->created_at->toIso8601String(),
                    'user' => [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                        'email' => $log->user->email,
                    ]
                ];
            })->toArray()
        ];
    }

    /**
     * Export audit logs to CSV
     */
    public function exportToCsv(string $companyId, array $filters = []): string
    {
        $query = $this->buildFilteredQuery($companyId, $filters);
        $logs = $query->with('user:id,first_name,last_name,email')
            ->orderBy('created_at', 'desc')
            ->get();

        $csv = "ID,Action,Description,Entity Type,Entity ID,User,Email,IP Address,Created At\n";
        
        foreach ($logs as $log) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s","%s","%s","%s","%s"' . "\n",
                $log->id,
                $log->action,
                $log->description,
                $log->entity_type ?? '',
                $log->entity_id ?? '',
                $log->user->name ?? 'System',
                $log->user->email ?? '',
                $log->ip_address ?? '',
                $log->created_at->format('Y-m-d H:i:s')
            );
        }

        return $csv;
    }

    /**
     * Build filtered query based on provided filters
     */
    private function buildFilteredQuery(string $companyId, array $filters): Builder
    {
        $query = AuditLog::where('company_id', $companyId);

        // Filter by action
        if (!empty($filters['action'])) {
            $query->where('action', 'like', '%' . $filters['action'] . '%');
        }

        // Filter by entity type
        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        // Filter by entity ID
        if (!empty($filters['entity_id'])) {
            $query->where('entity_id', $filters['entity_id']);
        }

        // Filter by user ID
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        // Filter by date range
        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['start_date'])->startOfDay());
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['end_date'])->endOfDay());
        }

        // Filter by IP address
        if (!empty($filters['ip_address'])) {
            $query->where('ip_address', 'like', '%' . $filters['ip_address'] . '%');
        }

        // Filter by description
        if (!empty($filters['description'])) {
            $query->where('description', 'like', '%' . $filters['description'] . '%');
        }

        return $query;
    }

    /**
     * Get activity by day for the last 30 days
     */
    private function getActivityByDay(string $companyId, array $filters): array
    {
        $startDate = Carbon::now()->subDays(30)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $query = AuditLog::where('company_id', $companyId)
            ->whereBetween('created_at', [$startDate, $endDate]);

        // Apply additional filters
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (!empty($filters['action'])) {
            $query->where('action', 'like', '%' . $filters['action'] . '%');
        }

        $activity = $query->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        return $activity->map(function ($item) {
            return [
                'date' => $item->date,
                'count' => $item->count
            ];
        })->toArray();
    }

    /**
     * Get top actions
     */
    private function getTopActions(string $companyId, array $filters): array
    {
        $query = AuditLog::where('company_id', $companyId);

        // Apply additional filters
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        $actions = $query->selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get();

        return $actions->map(function ($item) {
            return [
                'action' => $item->action,
                'count' => $item->count
            ];
        })->toArray();
    }

    /**
     * Get top users
     */
    private function getTopUsers(string $companyId, array $filters): array
    {
        $query = AuditLog::where('company_id', $companyId);

        // Apply additional filters
        if (!empty($filters['action'])) {
            $query->where('action', 'like', '%' . $filters['action'] . '%');
        }

        $users = $query->with('user:id,first_name,last_name,email')
            ->selectRaw('user_id, COUNT(*) as count')
            ->groupBy('user_id')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get();

        return $users->map(function ($item) {
            return [
                'user' => [
                    'id' => $item->user->id,
                    'name' => $item->user->name,
                    'email' => $item->user->email,
                ],
                'count' => $item->count
            ];
        })->toArray();
    }

    /**
     * Format paginated response
     */
    private function formatPaginatedResponse(LengthAwarePaginator $logs): array
    {
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