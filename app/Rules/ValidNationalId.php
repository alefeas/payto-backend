<?php

namespace App\Rules;

use App\Services\CuitValidatorService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidNationalId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = preg_replace('/[^0-9]/', '', $value);

        // DNI: 7-8 dígitos (sin validación de dígito verificador)
        if (strlen($value) >= 7 && strlen($value) <= 8) {
            return;
        }

        // CUIT/CUIL: 11 dígitos (con validación de dígito verificador)
        if (strlen($value) === 11) {
            if (!CuitValidatorService::isValid($value)) {
                $fail('El CUIT ingresado no es válido. Verifica el número y el dígito verificador.');
            }
            return;
        }

        $fail('El DNI debe tener 7-8 dígitos o el CUIT debe tener 11 dígitos');
    }
}
