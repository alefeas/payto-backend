<?php

namespace App\Repositories;

use App\Models\Notification;
use Illuminate\Database\Eloquent\Collection;

class NotificationRepository extends BaseRepository
{
    public function __construct(Notification $model)
    {
        parent::__construct($model);
    }

    public function getByCompanyId($companyId, $limit = null): Collection
    {
        $query = $this->model->where('company_id', $companyId)
            ->orderBy('created_at', 'desc');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    public function getUnreadByCompanyId($companyId): Collection
    {
        return $this->model->where('company_id', $companyId)
            ->where('read_at', null)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getUnreadCountByCompanyId($companyId): int
    {
        return $this->model->where('company_id', $companyId)
            ->where('read_at', null)
            ->count();
    }

    public function markAsRead($id): bool
    {
        $notification = $this->find($id);
        return $notification ? $notification->update(['read_at' => now()]) : false;
    }

    public function markAllAsReadByCompanyId($companyId): int
    {
        return $this->model->where('company_id', $companyId)
            ->where('read_at', null)
            ->update(['read_at' => now()]);
    }

    public function deleteOldNotifications($companyId, $daysOld = 30): int
    {
        return $this->model->where('company_id', $companyId)
            ->where('created_at', '<', now()->subDays($daysOld))
            ->delete();
    }
}
