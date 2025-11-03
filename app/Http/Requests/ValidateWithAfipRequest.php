<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateWithAfipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'issuer_cuit' => 'required|string',
            'invoice_type' => 'required|in:A,B,C,M,NCA,NCB,NCC,NCM,NDA,NDB,NDC,NDM',
            'invoice_number' => 'required|string|regex:/^\d{4}-\d{8}$/',
        ];
    }
}

