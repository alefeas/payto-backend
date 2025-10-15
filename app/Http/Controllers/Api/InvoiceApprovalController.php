<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        // Check if invoice has enough approvals
        $company = $invoice->receiverCompany;
        if ($invoice->approvals_received >= $company->required_approvals) {
            $invoice->update([
                'status' => 'approved',
                'approval_date' => now(),
            ]);
        }

        $invoice->refresh();

        return $this->success([
            'approvals_received' => $invoice->approvals_received,
            'approvals_required' => $company->required_approvals,
            'is_approved' => $invoice->status === 'approved',
        ], 'Factura aprobada exitosamente');
    }

    public function reject(Request $request, string $companyId, string $invoiceId): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $invoice = Invoice::findOrFail($invoiceId);
        $user = auth()->user();

        // Update invoice status
        $invoice->update([
            'status' => 'rejected',
            'rejection_reason' => $request->input('reason'),
            'rejected_at' => now(),
            'rejected_by' => $user->id,
        ]);

        return $this->success(null, 'Factura rechazada');
    }

    public function getApprovals(string $companyId, string $invoiceId): JsonResponse
    {
        $invoice = Invoice::with(['approvals.user'])->findOrFail($invoiceId);

        return $this->success([
            'approvals' => $invoice->approvals->map(function ($approval) {
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
            }),
            'approvals_received' => $invoice->approvals_received,
            'approvals_required' => $invoice->receiverCompany->required_approvals,
        ]);
    }
}
