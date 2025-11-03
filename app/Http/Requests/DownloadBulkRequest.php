<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DownloadBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_ids' => 'required|array|min:1|max:50',
            'invoice_ids.*' => 'required|exists:invoices,id',
            'format' => 'required|in:pdf,txt',
        ];
    }
}

