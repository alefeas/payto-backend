<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRetention;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function registerPayment(array $data, Company $company): Payment
    {
        return DB::transaction(function() use ($data, $company) {
            $invoice = Invoice::findOrFail($data['invoice_id']);
            
            // Validar que la factura pertenece a la empresa
            if ($invoice->receiver_company_id !== $company->id) {
                throw new \Exception('Invoice does not belong to this company');
            }
            
            // Crear pago confirmado directamente
            $payment = Payment::create([
                'company_id' => $company->id,
                'invoice_id' => $invoice->id,
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'confirmed',
                'registered_by' => auth()->id(),
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
            ]);
            
            // Aplicar retenciones manuales si se proporcionaron, sino aplicar automáticas
            if (!empty($data['retentions'])) {
                foreach ($data['retentions'] as $retention) {
                    PaymentRetention::create([
                        'payment_id' => $payment->id,
                        'type' => $retention['type'],
                        'name' => $retention['name'],
                        'rate' => $retention['rate'],
                        'base_amount' => $retention['base_amount'],
                        'amount' => $retention['amount'],
                        'certificate_number' => $retention['certificate_number'] ?? null,
                    ]);
                }
            } elseif ($company->is_retention_agent && !empty($company->auto_retentions)) {
                $this->applyAutoRetentions($payment, $company, $invoice);
            }
            
            // Actualizar estado de factura a paid si el pago cubre el total
            if ($data['amount'] >= $invoice->total) {
                $invoice->status = 'paid';
                $invoice->save();
            }
            
            return $payment->load('retentions');
        });
    }

    private function applyAutoRetentions(Payment $payment, Company $company, Invoice $invoice): void
    {
        foreach ($company->auto_retentions as $autoRetention) {
            $baseAmount = $this->calculateBaseAmount($invoice, $autoRetention['base_type']);
            $retentionAmount = $baseAmount * ($autoRetention['rate'] / 100);
            
            PaymentRetention::create([
                'payment_id' => $payment->id,
                'type' => $autoRetention['type'],
                'name' => $autoRetention['name'],
                'rate' => $autoRetention['rate'],
                'base_amount' => $baseAmount,
                'amount' => round($retentionAmount, 2),
            ]);
        }
    }

    private function calculateBaseAmount(Invoice $invoice, string $baseType): float
    {
        return match($baseType) {
            'net' => (float) ($invoice->subtotal ?? 0),
            'total' => (float) ($invoice->total ?? 0),
            'vat' => (float) ($invoice->total_taxes ?? 0),
            default => (float) ($invoice->subtotal ?? 0),
        };
    }

    public function confirmPayment(Payment $payment, int $userId): Payment
    {
        $payment->update([
            'status' => 'confirmed',
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
        ]);
        
        return $payment;
    }

    public function calculateRetentions(Invoice $invoice, Company $company): array
    {
        if (!$company->is_retention_agent || empty($company->auto_retentions)) {
            return [];
        }
        
        $retentions = [];
        
        foreach ($company->auto_retentions as $autoRetention) {
            $baseAmount = $this->calculateBaseAmount($invoice, $autoRetention['base_type']);
            $retentionAmount = $baseAmount * ($autoRetention['rate'] / 100);
            
            $retentions[] = [
                'type' => $autoRetention['type'],
                'name' => $autoRetention['name'],
                'rate' => $autoRetention['rate'],
                'base_amount' => $baseAmount,
                'amount' => round($retentionAmount, 2),
                'base_type' => $autoRetention['base_type'],
            ];
        }
        
        return $retentions;
    }

    public function generatePaymentTxt(array $paymentIds, Company $company): string
    {
        $payments = Payment::whereIn('id', $paymentIds)
            ->with(['invoice.issuerCompany', 'retentions'])
            ->get();
        
        $lines = [];
        $totalAmount = 0;
        
        foreach ($payments as $payment) {
            $supplier = $payment->invoice->issuerCompany;
            $netAmount = $payment->amount - $payment->retentions->sum('amount');
            
            // Formato estándar para homebanking (puede variar según banco)
            $line = sprintf(
                "%s;%s;%s;%s;%s;%s",
                $supplier->national_id,
                $supplier->business_name ?? $supplier->name,
                $supplier->cbu ?? '',
                number_format($netAmount, 2, '.', ''),
                $payment->reference_number ?? $payment->invoice->voucher_number,
                $payment->notes ?? ''
            );
            
            $lines[] = $line;
            $totalAmount += $netAmount;
        }
        
        // Header
        array_unshift($lines, sprintf(
            "CUIT;RAZON_SOCIAL;CBU;IMPORTE;REFERENCIA;CONCEPTO"
        ));
        
        // Footer
        $lines[] = sprintf(
            "TOTAL;%d;%s",
            count($payments),
            number_format($totalAmount, 2, '.', '')
        );
        
        return implode("\n", $lines);
    }
}
