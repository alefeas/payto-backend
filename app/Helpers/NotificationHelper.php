<?php

namespace App\Helpers;

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
        
        $payerName = $payment->payerCompany->business_name ?? 'Cliente';
        
        $service->createForCompanyMembers(
            $payment->payer_company_id,
            'payment_received',
            'Nuevo pago recibido',
            "{$payerName} declaró un pago de $" . number_format($payment->net_amount, 2),
            [
                'entityType' => 'payment',
                'entityId' => $payment->id,
                'amount' => $payment->net_amount,
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
}
