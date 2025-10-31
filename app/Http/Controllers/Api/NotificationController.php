<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get notifications for a company
     */
    public function index(Request $request, string $companyId): JsonResponse
    {
        $userId = $request->user()->id;
        $unreadOnly = $request->boolean('unread_only', false);
        $limit = $request->integer('limit', 50);

        $notifications = $this->notificationService->getNotifications(
            $userId,
            $companyId,
            $limit,
            $unreadOnly
        );

        return response()->json([
            'success' => true,
            'data' => $notifications->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'userId' => $notification->user_id,
                    'companyId' => $notification->company_id,
                    'companyName' => $notification->company->business_name ?? '',
                    'type' => $notification->type,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'data' => $notification->data,
                    'read' => $notification->read,
                    'createdAt' => $notification->created_at->toISOString(),
                ];
            }),
        ]);
    }

    /**
     * Get unread count
     */
    public function unreadCount(Request $request, string $companyId): JsonResponse
    {
        $userId = $request->user()->id;
        $count = $this->notificationService->getUnreadCount($userId, $companyId);

        return response()->json([
            'success' => true,
            'data' => [
                'count' => $count,
            ],
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $success = $this->notificationService->markAsRead($id);

        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request, string $companyId): JsonResponse
    {
        $userId = $request->user()->id;
        $count = $this->notificationService->markAllAsRead($userId, $companyId);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
            'data' => [
                'count' => $count,
            ],
        ]);
    }
}
