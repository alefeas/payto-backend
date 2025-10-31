<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\Company;

class NotificationService
{
    /**
     * Create a new notification
     */
    public function create(
        string $userId,
        string $companyId,
        string $type,
        string $title,
        string $message,
        array $data = []
    ): Notification {
        return Notification::create([
            'user_id' => $userId,
            'company_id' => $companyId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'read' => false,
        ]);
    }

    /**
     * Create notifications for all members of a company
     */
    public function createForCompanyMembers(
        string $companyId,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $excludeUserId = null
    ): void {
        $company = Company::with('members')->find($companyId);
        
        if (!$company) {
            return;
        }

        foreach ($company->members as $member) {
            if ($excludeUserId && $member->user_id === $excludeUserId) {
                continue;
            }

            $this->create(
                $member->user_id,
                $companyId,
                $type,
                $title,
                $message,
                $data
            );
        }
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(string $notificationId): bool
    {
        $notification = Notification::find($notificationId);
        
        if (!$notification) {
            return false;
        }

        $notification->update(['read' => true]);
        return true;
    }

    /**
     * Mark all notifications as read for a user in a company
     */
    public function markAllAsRead(string $userId, string $companyId): int
    {
        return Notification::where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('read', false)
            ->update(['read' => true]);
    }

    /**
     * Get unread count for a user in a company
     */
    public function getUnreadCount(string $userId, string $companyId): int
    {
        return Notification::where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('read', false)
            ->count();
    }

    /**
     * Get notifications for a user in a company
     */
    public function getNotifications(
        string $userId,
        string $companyId,
        int $limit = 50,
        bool $unreadOnly = false
    ) {
        $query = Notification::where('user_id', $userId)
            ->where('company_id', $companyId)
            ->with('company:id,business_name')
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($unreadOnly) {
            $query->where('read', false);
        }

        return $query->get();
    }
}
