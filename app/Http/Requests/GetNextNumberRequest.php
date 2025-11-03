<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetNextNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_point' => 'required|integer|min:1|max:9999',
            'invoice_type' => 'required|string',
        ];
    }
}

