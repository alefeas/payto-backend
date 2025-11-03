<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetAssociableInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_type' => 'required|string',
            'client_id' => 'nullable|exists:clients,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'receiver_document' => 'nullable|string',
            'issuer_document' => 'nullable|string',
        ];
    }
}

