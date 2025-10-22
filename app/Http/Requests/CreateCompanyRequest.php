<?php

namespace App\Http\Requests;

use App\Rules\ValidNationalId;
use Illuminate\Foundation\Http\FormRequest;

class CreateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:200',
            'business_name' => 'nullable|string|max:200',
            'national_id' => ['required', 'string', 'max:15', new ValidNationalId()],
            'phone' => 'nullable|string|max:20',
            'tax_condition' => 'nullable|in:RI,Monotributo,Exento,CF',
            'default_sales_point' => 'nullable|integer|min:1|max:9999',
            'deletion_code' => [
                'required',
                'string',
                'min:8',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[@$!%*#?&])[A-Za-z\\d@$!%*#?&]{8,}$/'
            ],
            'street' => 'required|string|max:200',
            'street_number' => 'required|string|max:10',
            'floor' => 'nullable|string|max:10',
            'apartment' => 'nullable|string|max:10',
            'postal_code' => 'required|string|max:8',
            'province' => 'required|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio',
            'name.max' => 'El nombre no puede exceder 200 caracteres',
            'national_id.required' => 'El CUIT/CUIL/DNI es obligatorio',


            'tax_condition.in' => 'La condición fiscal no es válida',
            'deletion_code.required' => 'El código de eliminación es obligatorio',
            'deletion_code.min' => 'El código de eliminación debe tener al menos 8 caracteres',
            'deletion_code.regex' => 'El código debe incluir mayúsculas, minúsculas, números y caracteres especiales (@$!%*#?&)',
            'street.required' => 'La calle es obligatoria',
            'street_number.required' => 'El número de calle es obligatorio',
            'postal_code.required' => 'El código postal es obligatorio',
            'province.required' => 'La provincia es obligatoria',
        ];
    }
}
