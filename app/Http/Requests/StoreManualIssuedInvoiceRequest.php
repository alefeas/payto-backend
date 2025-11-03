<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualIssuedInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => 'required_without_all:receiver_company_id,client_name,related_invoice_id|exists:clients,id',
            'receiver_company_id' => 'required_without_all:client_id,client_name,related_invoice_id|exists:companies,id',
            'client_name' => 'required_without_all:client_id,receiver_company_id,related_invoice_id|string|max:200',
            'client_document' => 'required_with:client_name|string|max:20',
            'related_invoice_id' => 'nullable|exists:invoices,id',
            'invoice_type' => 'required|string',
            'invoice_number' => 'nullable|string|max:50',
            'number' => 'nullable|string|max:50',
            'voucher_number' => 'nullable|max:50',
            'sales_point' => 'nullable|integer|min:1|max:9999',
            'concept' => 'nullable|in:products,services,products_services',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date|before:2031-01-01',
            'currency' => 'required|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:200',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount_percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate' => 'nullable|numeric|min:-2|max:100',
            'cae' => 'nullable|string|size:14|regex:/^[0-9]{14}$/',
            'cae_due_date' => 'nullable|date|required_with:cae',
            'service_date_from' => 'nullable|date|required_if:concept,services,products_services',
            'service_date_to' => 'nullable|date|after_or_equal:service_date_from|required_if:concept,services,products_services',
        ];
    }

    public function messages(): array
    {
        return [
            'client_name.required_without_all' => 'El nombre del cliente es obligatorio cuando no se selecciona un cliente o empresa existente.',
            'client_document.required_without_all' => 'El documento del cliente es obligatorio cuando no se selecciona un cliente o empresa existente.',
            'invoice_type.required' => 'El tipo de factura es obligatorio.',
            'issue_date.required' => 'La fecha de emisión es obligatoria.',
            'issue_date.date' => 'La fecha de emisión debe tener un formato válido (AAAA-MM-DD). Verifique que el día, mes y año sean correctos.',
            'due_date.required' => 'La fecha de vencimiento es obligatoria.',
            'due_date.date' => 'La fecha de vencimiento debe tener un formato válido (AAAA-MM-DD). Verifique que el día, mes y año sean correctos.',
            'due_date.after_or_equal' => 'La fecha de vencimiento debe ser igual o posterior a la fecha de emisión.',
            'due_date.before' => 'La fecha de vencimiento no puede ser posterior al año 2030. Seleccione una fecha hasta el 31/12/2030.',
            'cae.size' => 'El CAE debe tener exactamente 14 dígitos.',
            'cae.regex' => 'El CAE debe contener solo números (14 dígitos).',
            'cae_due_date.date' => 'La fecha de vencimiento del CAE debe tener un formato válido (AAAA-MM-DD).',
            'cae_due_date.required_with' => 'La fecha de vencimiento del CAE es obligatoria cuando se ingresa el número de CAE.',
            'service_date_from.required_if' => 'La fecha de inicio del servicio es obligatoria para servicios.',
            'service_date_to.required_if' => 'La fecha de fin del servicio es obligatoria para servicios.',
            'service_date_to.after_or_equal' => 'La fecha de fin del servicio debe ser igual o posterior a la fecha de inicio.',
            'currency.required' => 'La moneda es obligatoria.',
            'items.required' => 'Debe agregar al menos un ítem.',
            'items.*.description.required' => 'La descripción del ítem es obligatoria.',
            'items.*.quantity.required' => 'La cantidad es obligatoria.',
            'items.*.unit_price.required' => 'El precio unitario es obligatorio.',
        ];
    }
}

