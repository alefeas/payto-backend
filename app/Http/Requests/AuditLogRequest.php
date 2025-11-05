<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuditLogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'action' => 'nullable|string|max:255',
            'entity_type' => 'nullable|string|max:255',
            'entity_id' => 'nullable|string|uuid',
            'user_id' => 'nullable|string|uuid',
            'start_date' => 'nullable|date|date_format:Y-m-d',
            'end_date' => 'nullable|date|date_format:Y-m-d|after_or_equal:start_date',
            'ip_address' => 'nullable|ip',
            'description' => 'nullable|string|max:1000',
            'per_page' => 'nullable|integer|min:1|max:1000',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'La fecha final debe ser posterior o igual a la fecha inicial.',
            'per_page.min' => 'El número de elementos por página debe ser al menos 1.',
            'per_page.max' => 'El número de elementos por página no puede exceder 1000.',
            'limit.min' => 'El límite debe ser al menos 1.',
            'limit.max' => 'El límite no puede exceder 100.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'action' => 'acción',
            'entity_type' => 'tipo de entidad',
            'entity_id' => 'ID de entidad',
            'user_id' => 'ID de usuario',
            'start_date' => 'fecha inicial',
            'end_date' => 'fecha final',
            'ip_address' => 'dirección IP',
            'description' => 'descripción',
            'per_page' => 'elementos por página',
            'limit' => 'límite',
        ];
    }
}