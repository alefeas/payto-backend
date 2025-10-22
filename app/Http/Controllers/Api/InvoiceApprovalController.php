<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceApprovalController extends Controller
{
    use ApiResponse;

    public function approve(Request $request, string $companyId, string $invoiceId): JsonResponse
    {
        $invoice = Invoice::findOrFail($invoiceId);
        $user = auth()->user();

        // Check if user already approved
        $existingApproval = InvoiceApproval::where('invoice_id', $invoiceId)
            ->where('approved_by', $user->id)
            ->first();

        if ($existingApproval) {
            return $this->error('Ya aprobaste esta factura', 400);
        }

        // Create approval
        InvoiceApproval::create([
            'invoice_id' => $invoiceId,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'notes' => $request->input('notes'),
        ]);

        // Update invoice approvals count
        $invoice->increment('approvals_received');
        $invoice->refresh();

        // Actualizar estado solo para la empresa receptora
        $company = Company::findOrFail($companyId);
        $requiredApprovals = $company->required_approvals ?? 0;
        
        if ($requiredApprovals === 0 || $invoice->approvals_received >= $requiredApprovals) {
            // Guardar estado en JSON por empresa
            $companyStatuses = $invoice->company_statuses ?: [];
            $companyStatuses[(int)$companyId] = 'approved';
            
            $invoice->company_statuses = $companyStatuses;
            $invoice->approval_date = now();
            $invoice->save();
        }

        $invoice->refresh();
        
        // Check if invoice is fully approved
        $isFullyApproved = $requiredApprovals === 0 || $invoice->approvals_received >= $requiredApprovals;

        return $this->success([
            'approvals_received' => $invoice->approvals_received,
            'approvals_required' => $company->required_approvals,
            'is_approved' => $isFullyApproved,
        ], 'Factura aprobada exitosamente');
    }

    public function reject(Request $request, string $companyId, string $invoiceId): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $invoice = Invoice::findOrFail($invoiceId);
        $user = auth()->user();

        // Check if invoice has payments
        if ($invoice->payments()->exists()) {
            return $this->error('No se puede rechazar una factura con pagos registrados', 422);
        }

        // Actualizar estado solo para esta empresa
        $companyStatuses = $invoice->company_statuses ?: [];
        $companyStatuses[(int)$companyId] = 'rejected';
        
        $invoice->company_statuses = $companyStatuses;
        $invoice->rejection_reason = $request->input('reason');
        $invoice->rejected_at = now();
        $invoice->rejected_by = $user->id;
        $invoice->save();

        return $this->success(null, 'Factura rechazada');
    }

    public function getApprovals(string $companyId, string $invoiceId): JsonResponse
    {
        $invoice = Invoice::with(['approvals.user'])->findOrFail($invoiceId);

        $approvals = $invoice->approvals->map(function ($approval) {
            return [
                'id' => $approval->id,
                'user' => [
                    'id' => $approval->user->id,
                    'name' => $approval->user->name,
                    'email' => $approval->user->email,
                ],
                'notes' => $approval->notes,
                'approved_at' => $approval->approved_at->toIso8601String(),
            ];
        })->values();

        return $this->success([
            'approvals' => $approvals,
            'approvals_received' => $invoice->approvals_received,
            'approvals_required' => $invoice->receiverCompany->required_approvals,
        ]);
    }
}
