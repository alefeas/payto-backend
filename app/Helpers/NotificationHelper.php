<?php

namespace App\Helpers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\CompanyConnection;
use App\Services\NotificationService;

class NotificationHelper
{
    /**
     * Notify when a new invoice is received
     */
    public static function notifyInvoiceReceived($invoice, $excludeUserId = null)
    {
        if (!$invoice->receiver_company_id) {
            return;
        }

        $service = app(NotificationService::class);
        
        $issuerName = $invoice->issuerCompany->business_name ?? 
                     $invoice->supplier->business_name ?? 
                     'Proveedor';
        
        $service->createForCompanyMembers(
            $invoice->receiver_company_id,
            'invoice_received',
            'Nueva factura recibida',
            "{$issuerName} envió la factura {$invoice->number} por $" . number_format($invoice->total, 2),
            [
                'entityType' => 'invoice',
                'entityId' => $invoice->id,
                'amount' => $invoice->total,
                'fromCompany' => $issuerName,
            ],
            $excludeUserId
        );
    }

    /**
     * Notify when invoice needs approval
     */
    public static function notifyInvoicePendingApproval($invoice, $excludeUserId = null)
    {
        if (!$invoice->receiver_company_id) {
            return;
        }

        $service = app(NotificationService::class);
        
        $issuerName = $invoice->issuerCompany->business_name ?? 
                     $invoice->supplier->business_name ?? 
                     'Proveedor';
        
        $service->createForCompanyMembers(
            $invoice->receiver_company_id,
            'invoice_pending_approval',
            'Factura pendiente de aprobación',
            "La factura {$invoice->number} de {$issuerName} requiere tu aprobación",
            [
                'entityType' => 'invoice',
                'entityId' => $invoice->id,
                'amount' => $invoice->total,
                'fromCompany' => $issuerName,
            ],
            $excludeUserId
        );
    }

    /**
     * Notify when a payment is received
     */
    public static function notifyPaymentReceived($payment, $excludeUserId = null)
    {
        $service = app(NotificationService::class);
        
        // Get the payer company name from the invoice issuer
        $payerName = $payment->invoice->issuerCompany->business_name ?? 'Cliente';
        
        $service->createForCompanyMembers(
            $payment->invoice->issuer_company_id, // The company that issued the invoice (payer)
            'payment_received',
            'Nuevo pago recibido',
            "{$payerName} declaró un pago de $" . number_format($payment->amount, 2),
            [
                'entityType' => 'payment',
                'entityId' => $payment->id,
                'amount' => $payment->amount,
                'fromCompany' => $payerName,
            ],
            $excludeUserId
        );
    }

    /**
     * Notify when a connection request is received
     */
    public static function notifyConnectionRequest($connection, $excludeUserId = null)
    {
        $service = app(NotificationService::class);
        
        $requesterName = $connection->company->business_name ?? 'Empresa';
        
        $service->createForCompanyMembers(
            $connection->connected_company_id,
            'connection_request',
            'Nueva solicitud de conexión',
            "{$requesterName} quiere conectarse con tu empresa",
            [
                'entityType' => 'connection',
                'entityId' => $connection->id,
                'fromCompany' => $requesterName,
            ],
            $excludeUserId
        );
    }

    /**
     * Notify when invoice status changes
     */
    public static function notifyInvoiceStatusChanged(Invoice $invoice, string $oldStatus, string $newStatus, $excludeUserId = null)
    {
        $service = app(NotificationService::class);
        
        $statusMessages = [
            'approved' => 'aprobada',
            'rejected' => 'rechazada',
            'paid' => 'pagada',
            'collected' => 'cobrada',
            'partially_paid' => 'pagada parcialmente',
            'cancelled' => 'cancelada',
            'dispute' => 'en disputa',
            'needs_review' => 'requiere revisión',
        ];

        $statusMessage = $statusMessages[$newStatus] ?? $newStatus;
        
        $service->createForCompanyMembers(
            $invoice->issuer_company_id,
            'invoice_status_changed',
            'Estado de factura actualizado',
            "La factura {$invoice->number} ha sido {$statusMessage}",
            [
                'entityType' => 'invoice',
                'entityId' => $invoice->id,
                'oldStatus' => $oldStatus,
                'newStatus' => $newStatus,
                'amount' => $invoice->total,
            ],
            $excludeUserId
        );
    }

    /**
     * Notify when payment status changes
     */
    public static function notifyPaymentStatusChanged(Payment $payment, string $oldStatus, string $newStatus, $excludeUserId = null)
    {
        $service = app(NotificationService::class);
        
        $statusMessages = [
            'confirmed' => 'confirmado',
            'rejected' => 'rechazado',
            'pending' => 'pendiente',
            'cancelled' => 'cancelado',
        ];

        $statusMessage = $statusMessages[$newStatus] ?? $newStatus;
        
        $service->createForCompanyMembers(
            $payment->company_id,
            'payment_status_changed',
            'Estado de pago actualizado',
            "El pago por $" . number_format($payment->amount, 2) . " ha sido {$statusMessage}",
            [
                'entityType' => 'payment',
                'entityId' => $payment->id,
                'oldStatus' => $oldStatus,
                'newStatus' => $newStatus,
                'amount' => $payment->amount,
            ],
            $excludeUserId
        );
    }

    /**
     * Notify when invoice is due soon
     */
    public static function notifyInvoiceDueSoon(Invoice $invoice, int $daysUntilDue, $excludeUserId = null)
    {
        $service = app(NotificationService::class);
        
        $title = $daysUntilDue === 1 ? 'Factura vence mañana' : "Factura vence en {$daysUntilDue} días";
        $message = "La factura {$invoice->number} vence el {$invoice->due_date->format('d/m/Y')}";
        
        $service->createForCompanyMembers(
            $invoice->issuer_company_id,
            'invoice_due_soon',
            $title,
            $message,
            [
                'entityType' => 'invoice',
                'entityId' => $invoice->id,
                'dueDate' => $invoice->due_date->format('Y-m-d'),
                'daysUntilDue' => $daysUntilDue,
                'amount' => $invoice->balance_pending,
            ],
            $excludeUserId
        );
    }

    /**
     * Notify when invoice is overdue
     */
    public static function notifyInvoiceOverdue(Invoice $invoice, int $daysOverdue, $excludeUserId = null)
    {
        $service = app(NotificationService::class);
        
        $title = 'Factura vencida';
        $message = "La factura {$invoice->number} está vencida hace {$daysOverdue} días";
        
        $service->createForCompanyMembers(
            $invoice->issuer_company_id,
            'invoice_overdue',
            $title,
            $message,
            [
                'entityType' => 'invoice',
                'entityId' => $invoice->id,
                'dueDate' => $invoice->due_date->format('Y-m-d'),
                'daysOverdue' => $daysOverdue,
                'amount' => $invoice->balance_pending,
            ],
            $excludeUserId
        );
    }

    /**
     * Notify when connection request is accepted
     */
    public static function notifyConnectionAccepted(CompanyConnection $connection, $excludeUserId = null)
    {
        $service = app(NotificationService::class);
        
        $connectedName = $connection->connectedCompany->business_name ?? 'Empresa';
        
        $service->createForCompanyMembers(
            $connection->company_id,
            'connection_accepted',
            'Conexión aceptada',
            "{$connectedName} aceptó tu solicitud de conexión",
            [
                'entityType' => 'connection',
                'entityId' => $connection->id,
                'connectedCompany' => $connectedName,
            ],
            $excludeUserId
        );
    }

    /**
     * Notify when connection request is rejected
     */
    public static function notifyConnectionRejected(CompanyConnection $connection, $excludeUserId = null)
    {
        $service = app(NotificationService::class);
        
        $connectedName = $connection->connectedCompany->business_name ?? 'Empresa';
        
        $service->createForCompanyMembers(
            $connection->company_id,
            'connection_rejected',
            'Conexión rechazada',
            "{$connectedName} rechazó tu solicitud de conexión",
            [
                'entityType' => 'connection',
                'entityId' => $connection->id,
                'connectedCompany' => $connectedName,
            ],
            $excludeUserId
        );
    }

    /**
     * Notify system alert
     */
    public static function notifySystemAlert(string $companyId, string $title, string $message, array $data = [], $excludeUserId = null)
    {
        $service = app(NotificationService::class);
        
        $service->createForCompanyMembers(
            $companyId,
            'system_alert',
            $title,
            $message,
            $data,
            $excludeUserId
        );
    }

    /**
     * Notify when invoice needs review
     */
    public static function notifyInvoiceNeedsReview(Invoice $invoice, string $reason, $excludeUserId = null)
    {
        $service = app(NotificationService::class);
        
        $service->createForCompanyMembers(
            $invoice->issuer_company_id,
            'invoice_needs_review',
            'Factura requiere revisión',
            "La factura {$invoice->number} requiere revisión: {$reason}",
            [
                'entityType' => 'invoice',
                'entityId' => $invoice->id,
                'reason' => $reason,
                'amount' => $invoice->total,
            ],
            $excludeUserId
        );
    }

    /**
     * Notify when a new member joins the company
     */
    public static function notifyMemberJoined(string $companyId, $newMember, $excludeUserId = null)
    {
        $service = app(NotificationService::class);
        
        $memberName = $newMember->user->name ?? 'Nuevo miembro';
        $role = $newMember->role ?? 'operator';
        
        $roleNames = [
            'owner' => 'Dueño',
            'administrator' => 'Administrador',
            'financial_director' => 'Director Financiero',
            'accountant' => 'Contador',
            'approver' => 'Aprobador',
            'operator' => 'Operador',
            'viewer' => 'Visualizador',
        ];
        
        $roleName = $roleNames[$role] ?? $role;
        
        $service->createForCompanyMembers(
            $companyId,
            'member_joined',
            'Nuevo miembro en la empresa',
            "{$memberName} se unió como {$roleName}",
            [
                'entityType' => 'member',
                'entityId' => $newMember->id,
                'memberName' => $memberName,
                'role' => $role,
            ],
            $excludeUserId
        );
    }

    /**
     * Notify when a member leaves the company
     */
    public static function notifyMemberLeft(string $companyId, $member, $excludeUserId = null)
    {
        $service = app(NotificationService::class);
        
        $memberName = $member->user->name ?? 'Miembro';
        
        $service->createForCompanyMembers(
            $companyId,
            'member_left',
            'Miembro dejó la empresa',
            "{$memberName} ya no es parte de la empresa",
            [
                'entityType' => 'member',
                'entityId' => $member->id,
                'memberName' => $memberName,
            ],
            $excludeUserId
        );
    }

    /**
     * Notify when a member's role changes
     */
    public static function notifyMemberRoleChanged(string $companyId, $member, string $oldRole, string $newRole, $excludeUserId = null)
    {
        $service = app(NotificationService::class);
        
        $memberName = $member->user->name ?? 'Miembro';
        
        $roleNames = [
            'owner' => 'Dueño',
            'administrator' => 'Administrador',
            'financial_director' => 'Director Financiero',
            'accountant' => 'Contador',
            'approver' => 'Aprobador',
            'operator' => 'Operador',
            'viewer' => 'Visualizador',
        ];
        
        $oldRoleName = $roleNames[$oldRole] ?? $oldRole;
        $newRoleName = $roleNames[$newRole] ?? $newRole;
        
        $service->createForCompanyMembers(
            $companyId,
            'member_role_changed',
            'Rol de miembro actualizado',
            "El rol de {$memberName} cambió de {$oldRoleName} a {$newRoleName}",
            [
                'entityType' => 'member',
                'entityId' => $member->id,
                'memberName' => $memberName,
                'oldRole' => $oldRole,
                'newRole' => $newRole,
            ],
            $excludeUserId
        );
    }
}
