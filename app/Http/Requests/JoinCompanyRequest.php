<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invite_code' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'invite_code.required' => 'El código de invitación es obligatorio',
        ];
    }
}
