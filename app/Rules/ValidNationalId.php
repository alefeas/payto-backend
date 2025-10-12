<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidNationalId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = preg_replace('/[^0-9]/', '', $value);

        if (strlen($value) === 8) {
            return;
        }

        if (strlen($value) === 11) {
            if (!$this->validateCuitCuil($value)) {
                $fail('El CUIT/CUIL ingresado no es válido');
            }
            return;
        }

        $fail('El CUIT/CUIL/DNI debe tener 8 dígitos (DNI) u 11 dígitos (CUIT/CUIL)');
    }

    private function validateCuitCuil(string $cuit): bool
    {
        if (strlen($cuit) !== 11) {
            return false;
        }

        $multipliers = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        for ($i = 0; $i < 10; $i++) {
            $sum += intval($cuit[$i]) * $multipliers[$i];
        }

        $verifier = 11 - ($sum % 11);
        if ($verifier === 11) $verifier = 0;
        if ($verifier === 10) $verifier = 9;

        return intval($cuit[10]) === $verifier;
    }
}
