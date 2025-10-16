<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Validator;

class VoucherValidationService
{
    public function validateVoucher(array $data, string $type): array
    {
        $category = VoucherTypeService::getCategory($type);
        
        // Validaciones base
        $rules = $this->getBaseRules($type);
        
        // Validaciones específicas por categoría
        switch ($category) {
            case 'credit_note':
            case 'debit_note':
                $rules = array_merge($rules, $this->getAssociatedVoucherRules());
                break;
            case 'fce_mipyme':
                $rules = array_merge($rules, $this->getFCEMipymeRules());
                break;
            case 'remito':
                $rules = array_merge($rules, $this->getRemitoRules());
                break;
            case 'used_goods_purchase':
                $rules = array_merge($rules, $this->getUsedGoodsPurchaseRules());
                break;
        }
        
        $validator = Validator::make($data, $rules);
        
        if ($validator->fails()) {
            return ['valid' => false, 'errors' => $validator->errors()->toArray()];
        }
        
        // Validaciones de negocio
        $businessValidation = $this->validateBusinessRules($data, $type);
        if (!$businessValidation['valid']) {
            return $businessValidation;
        }
        
        return ['valid' => true];
    }
    
    private function getBaseRules(string $type): array
    {
        $hasAmounts = VoucherTypeService::hasAmounts($type);
        
        $rules = [
            'client_id' => 'required_without:client_data|exists:clients,id',
            'sales_point' => 'required|integer|min:1|max:9999',
            'issue_date' => 'required|date',
        ];
        
        if ($hasAmounts) {
            $rules = array_merge($rules, [
                'items' => 'required|array|min:1',
                'items.*.description' => 'required|string',
                'items.*.quantity' => 'required|numeric|min:0.01',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.tax_rate' => 'required|numeric',
            ]);
        }
        
        return $rules;
    }
    
    private function getAssociatedVoucherRules(): array
    {
        return [
            'related_invoice_id' => 'required|exists:invoices,id',
        ];
    }
    
    private function getFCEMipymeRules(): array
    {
        return [
            'payment_due_date' => 'required|date|after:issue_date',
            'issuer_cbu' => 'required|string|size:22',
        ];
    }
    
    private function getRemitoRules(): array
    {
        return [
            'transport_data' => 'required|array',
            'transport_data.origin' => 'required|string',
            'transport_data.destination' => 'required|string',
            'transport_data.carrier_cuit' => 'nullable|string',
            'transport_data.vehicle_plate' => 'nullable|string',
        ];
    }
    
    private function getUsedGoodsPurchaseRules(): array
    {
        return [
            'seller_data' => 'required|array',
            'seller_data.document_type' => 'required|in:DNI,CUIL,CUIT',
            'seller_data.document_number' => 'required|string',
            'seller_data.name' => 'required|string',
            'seller_data.address' => 'required|string',
        ];
    }
    
    private function validateBusinessRules(array $data, string $type): array
    {
        $category = VoucherTypeService::getCategory($type);
        
        // Validar NC/ND
        if (in_array($category, ['credit_note', 'debit_note'])) {
            return $this->validateAssociatedVoucher($data, $type);
        }
        
        // Validar FCE MiPyME
        if ($category === 'fce_mipyme') {
            return $this->validateFCEMipyme($data);
        }
        
        return ['valid' => true];
    }
    
    private function validateAssociatedVoucher(array $data, string $type): array
    {
        $relatedInvoice = Invoice::find($data['related_invoice_id']);
        
        if (!$relatedInvoice) {
            return ['valid' => false, 'errors' => ['related_invoice_id' => ['Factura no encontrada']]];
        }
        
        // Validar compatibilidad de tipos
        if (!VoucherTypeService::isCompatibleWith($type, $relatedInvoice->type)) {
            return ['valid' => false, 'errors' => ['type' => ['Tipo de comprobante incompatible con la factura original']]];
        }
        
        // Validar monto disponible
        $category = VoucherTypeService::getCategory($type);
        $totalRequested = $this->calculateTotal($data);
        $availableBalance = $relatedInvoice->balance_pending ?? $relatedInvoice->total;
        
        if ($category === 'credit_note' && $totalRequested > $availableBalance) {
            return ['valid' => false, 'errors' => ['total' => ['El monto excede el saldo disponible de la factura']]];
        }
        
        return ['valid' => true];
    }
    
    private function validateFCEMipyme(array $data): array
    {
        // Aquí validarías que la empresa esté inscripta en el régimen MiPyME
        // Por ahora retornamos válido
        return ['valid' => true];
    }
    
    private function calculateTotal(array $data): float
    {
        $subtotal = 0;
        $totalTaxes = 0;
        
        foreach ($data['items'] ?? [] as $item) {
            $itemSubtotal = $item['quantity'] * $item['unit_price'];
            $itemTax = $itemSubtotal * ($item['tax_rate'] / 100);
            $subtotal += $itemSubtotal;
            $totalTaxes += $itemTax;
        }
        
        return $subtotal + $totalTaxes;
    }
}
