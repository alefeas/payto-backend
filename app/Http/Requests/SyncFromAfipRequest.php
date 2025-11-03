<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncFromAfipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => 'required|in:single,date_range',
            'sales_point' => 'required_if:mode,single|integer|min:1|max:9999',
            'invoice_type' => 'required_if:mode,single|string',
            'invoice_number' => 'required_if:mode,single|integer|min:1',
            'date_from' => 'required_if:mode,date_range|date',
            'date_to' => 'required_if:mode,date_range|date|after_or_equal:date_from',
        ];
    }
}

