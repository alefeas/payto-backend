<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $validationMessages = array_merge(
            config('afip_rules.validation_messages', []),
            [
                'items.*.quantity.max' => 'La cantidad no puede superar las 999,999 unidades. Si necesitás facturar más, dividí en múltiples ítems.',
                'items.*.unit_price.max' => 'El precio unitario no puede superar $999,999,999. Si necesitás facturar montos mayores, dividí en múltiples ítems.',
                'due_date.date' => 'La fecha de vencimiento debe ser una fecha válida.',
            ]
        );

        return [
            'client_id' => 'required_without_all:client_data,receiver_company_id|exists:clients,id',
            'receiver_company_id' => 'nullable|exists:companies,id',
            'client_data' => 'required_without_all:client_id,receiver_company_id|array',
            'client_data.document_type' => 'required_with:client_data|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'client_data.document_number' => 'required_with:client_data|string',
            'client_data.business_name' => 'nullable|string',
            'client_data.first_name' => 'nullable|string',
            'client_data.last_name' => 'nullable|string',
            'client_data.email' => 'nullable|email',
            'client_data.tax_condition' => 'required_with:client_data|in:registered_taxpayer,monotax,exempt,final_consumer',
            'save_client' => 'boolean',
            'invoice_type' => 'required|string',
            'sales_point' => 'required|integer|min:1|max:9999',
            'concept' => 'required|in:products,services,products_services',
            'service_date_from' => 'nullable|date',
            'service_date_to' => 'nullable|date|after_or_equal:service_date_from',
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:issue_date|before:2030-01-01',
            'currency' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01|max:999999',
            'items.*.unit_price' => 'required|numeric|min:0|max:999999999',
            'items.*.discount_percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate' => 'nullable|numeric|min:-2|max:100',
            'perceptions' => 'nullable|array',
            'perceptions.*.type' => 'required|string|max:100',
            'perceptions.*.name' => 'required|string|max:100',
            'perceptions.*.rate' => 'nullable|numeric|min:0|max:100',
            'perceptions.*.amount' => 'nullable|numeric|min:0',
            'perceptions.*.jurisdiction' => 'nullable|string|max:100',
            'perceptions.*.base_type' => 'nullable|in:net,total,vat',
        ];
    }

    public function messages(): array
    {
        return array_merge(
            config('afip_rules.validation_messages', []),
            [
                'items.*.quantity.max' => 'La cantidad no puede superar las 999,999 unidades. Si necesitás facturar más, dividí en múltiples ítems.',
                'items.*.unit_price.max' => 'El precio unitario no puede superar $999,999,999. Si necesitás facturar montos mayores, dividí en múltiples ítems.',
                'due_date.date' => 'La fecha de vencimiento debe ser una fecha válida.',
            ]
        );
    }
}

