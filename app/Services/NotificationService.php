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
     * Now creates a SINGLE notification for the company (not one per member)
     */
    public function createForCompanyMembers(
        string $companyId,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $excludeUserId = null
    ): void {
        $company = Company::find($companyId);
        
        if (!$company) {
            return;
        }

        // Create a single notification for the company
        // user_id is set to null since it's a company-wide notification
        Notification::create([
            'user_id' => null, // Company-wide notification
            'company_id' => $companyId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'read' => false,
        ]);
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
     * Now marks ALL company notifications as read
     */
    public function markAllAsRead(string $userId, string $companyId): int
    {
        return Notification::where('company_id', $companyId)
            ->where('read', false)
            ->update(['read' => true]);
    }

    /**
     * Get unread count for a user in a company
     * Now counts ALL company notifications, not just user-specific ones
     */
    public function getUnreadCount(string $userId, string $companyId): int
    {
        return Notification::where('company_id', $companyId)
            ->where('read', false)
            ->count();
    }

    /**
     * Get notifications for a user in a company
     * Now returns ALL company notifications, not just user-specific ones
     */
    public function getNotifications(
        string $userId,
        string $companyId,
        int $limit = 50,
        bool $unreadOnly = false
    ) {
        // Get all notifications for the company (not filtered by user_id)
        $query = Notification::where('company_id', $companyId)
            ->with('company:id,business_name')
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($unreadOnly) {
            $query->where('read', false);
        }

        return $query->get();
    }
}
