<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => 'sometimes|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'document_number' => 'sometimes|string|max:20',
            'business_name' => 'nullable|string|max:255',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'tax_condition' => 'sometimes|in:registered_taxpayer,monotax,exempt,final_consumer',
            
            // Domicilio fiscal obligatorio solo si NO es consumidor final
            'fiscal_address' => 'required_unless:tax_condition,final_consumer|nullable|string|max:255',
            'postal_code' => 'required_with:fiscal_address|nullable|string|max:10',
            'city' => 'required_with:fiscal_address|nullable|string|max:100',
            'province' => 'required_with:fiscal_address|nullable|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'fiscal_address.required_unless' => 'El domicilio fiscal es obligatorio para contribuyentes inscriptos.',
            'postal_code.required_with' => 'El código postal es obligatorio cuando se especifica domicilio fiscal.',
            'city.required_with' => 'La ciudad es obligatoria cuando se especifica domicilio fiscal.',
            'province.required_with' => 'La provincia es obligatoria cuando se especifica domicilio fiscal.',
        ];
    }
}