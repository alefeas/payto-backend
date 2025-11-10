<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualReceivedInvoiceRequest extends FormRequest
{
    protected function prepareForValidation()
    {
        if (is_string($this->items)) {
            $this->merge(['items' => json_decode($this->items, true)]);
        }
        if (is_string($this->perceptions)) {
            $this->merge(['perceptions' => json_decode($this->perceptions, true)]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => 'required_without_all:issuer_company_id,supplier_name,related_invoice_id|exists:suppliers,id',
            'issuer_company_id' => 'required_without_all:supplier_id,supplier_name,related_invoice_id|string',
            'supplier_name' => 'required_without_all:issuer_company_id,supplier_id,related_invoice_id|string|max:200',
            'supplier_document' => 'required_with:supplier_name|string|max:20',
            'related_invoice_id' => 'nullable|exists:invoices,id',
            'invoice_type' => 'required|string',
            'invoice_number' => 'nullable|string|max:50',
            'number' => 'nullable|string|max:50',
            'voucher_number' => 'nullable|max:50',
            'sales_point' => 'nullable|integer|min:1|max:9999',
            'concept' => 'nullable|in:products,services,products_services',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date|before:2030-01-01',
            'currency' => 'required|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:200',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount_percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate' => 'nullable|numeric|min:-2|max:100',
            'cae' => 'nullable|string|max:20',
            'cae_due_date' => 'nullable|date',
            'perceptions' => 'nullable|array',
            'perceptions.*.type' => 'required|string|max:100',
            'perceptions.*.name' => 'required|string|max:100',
            'perceptions.*.rate' => 'nullable|numeric|min:0|max:100',
            'perceptions.*.amount' => 'nullable|numeric|min:0',
            'perceptions.*.jurisdiction' => 'nullable|string|max:100',
            'perceptions.*.base_type' => 'nullable|in:net,total,vat',
            'attachment' => 'nullable|file|mimes:pdf|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.exists' => 'El proveedor seleccionado no existe.',
            'supplier_name.required_without' => 'El nombre del proveedor es obligatorio cuando no se selecciona un proveedor existente.',
            'supplier_name.max' => 'El nombre del proveedor no puede superar los 200 caracteres.',
            'supplier_document.required_without' => 'El documento del proveedor es obligatorio cuando no se selecciona un proveedor existente.',
            'supplier_document.max' => 'El documento del proveedor no puede superar los 20 caracteres.',
            'invoice_type.required' => 'El tipo de factura es obligatorio.',
            'invoice_type.in' => 'El tipo de factura seleccionado no es válido.',
            'invoice_number.required' => 'El número de factura es obligatorio.',
            'invoice_number.max' => 'El número de factura no puede superar los 50 caracteres.',
            'issue_date.required' => 'La fecha de emisión es obligatoria.',
            'issue_date.date' => 'La fecha de emisión debe ser una fecha válida.',
            'due_date.required' => 'La fecha de vencimiento es obligatoria.',
            'due_date.date' => 'La fecha de vencimiento debe ser una fecha válida.',
            'due_date.after_or_equal' => 'La fecha de vencimiento debe ser igual o posterior a la fecha de emisión.',
            'due_date.before' => 'La fecha de vencimiento no puede ser posterior al año 2030.',
            'currency.required' => 'La moneda es obligatoria.',
            'currency.size' => 'La moneda debe tener exactamente 3 caracteres.',
            'exchange_rate.numeric' => 'El tipo de cambio debe ser un número.',
            'exchange_rate.min' => 'El tipo de cambio debe ser mayor o igual a 0.',
            'notes.max' => 'Las notas no pueden superar los 500 caracteres.',
            'items.required' => 'Debe agregar al menos un ítem.',
            'items.min' => 'Debe agregar al menos un ítem.',
            'items.*.description.required' => 'La descripción del ítem es obligatoria.',
            'items.*.description.max' => 'La descripción del ítem no puede superar los 200 caracteres.',
            'items.*.quantity.required' => 'La cantidad es obligatoria.',
            'items.*.quantity.numeric' => 'La cantidad debe ser un número.',
            'items.*.quantity.min' => 'La cantidad debe ser mayor a 0.',
            'items.*.unit_price.required' => 'El precio unitario es obligatorio.',
            'items.*.unit_price.numeric' => 'El precio unitario debe ser un número.',
            'items.*.unit_price.min' => 'El precio unitario debe ser mayor o igual a 0.',
            'items.*.tax_rate.numeric' => 'La tasa de impuesto debe ser un número.',
            'items.*.tax_rate.min' => 'La tasa de impuesto debe ser mayor o igual a -2 (use -1 para Exento, -2 para No Gravado).',
            'items.*.tax_rate.max' => 'La tasa de impuesto no puede superar el 100%.',
        ];
    }
}

