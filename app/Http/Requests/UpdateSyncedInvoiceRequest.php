<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSyncedInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'concept' => 'required|in:products,services,products_services',
            'service_date_from' => 'nullable|date',
            'service_date_to' => 'nullable|date|after_or_equal:service_date_from',
            'items' => 'required|array',
            'items.*.description' => 'required|string|max:200',
        ];
    }

    public function messages(): array
    {
        return [
            'service_date_to.after_or_equal' => 'La fecha de fin del servicio debe ser igual o posterior a la fecha de inicio',
        ];
    }
}

