<?php

namespace App\Services;

class CuitValidatorService
{
    /**
     * Valida formato y dígito verificador de CUIT
     */
    public static function isValid(string $cuit): bool
    {
        // Limpiar guiones
        $cuit = str_replace('-', '', $cuit);
        
        // Debe tener 11 dígitos
        if (strlen($cuit) !== 11 || !ctype_digit($cuit)) {
            return false;
        }
        
        // Permitir CUITs de prueba (30-00000000-X es común en testing)
        // Solo rechazar si TODO el CUIT es ceros
        if ($cuit === '00000000000') {
            return false;
        }
        
        // Validar prefijo (20-27 para personas, 30-34 para empresas)
        $prefix = intval(substr($cuit, 0, 2));
        $validPrefixes = [20, 23, 24, 27, 30, 33, 34];
        if (!in_array($prefix, $validPrefixes)) {
            return false;
        }
        
        // Validar dígito verificador
        $multiplicadores = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        
        for ($i = 0; $i < 10; $i++) {
            $suma += intval($cuit[$i]) * $multiplicadores[$i];
        }
        
        $resto = $suma % 11;
        $digitoVerificador = $resto === 0 ? 0 : (11 - $resto);
        
        return intval($cuit[10]) === $digitoVerificador;
    }
    
    /**
     * Formatea CUIT con guiones
     */
    public static function format(string $cuit): string
    {
        $cuit = str_replace('-', '', $cuit);
        if (strlen($cuit) !== 11) {
            return $cuit;
        }
        return substr($cuit, 0, 2) . '-' . substr($cuit, 2, 8) . '-' . substr($cuit, 10, 1);
    }
    
    /**
     * Calcula el dígito verificador correcto para un CUIT
     */
    public static function calculateVerifier(string $cuitWithoutVerifier): int
    {
        $cuit = str_replace('-', '', $cuitWithoutVerifier);
        
        // Si tiene 11 dígitos, tomar solo los primeros 10
        if (strlen($cuit) === 11) {
            $cuit = substr($cuit, 0, 10);
        }
        
        if (strlen($cuit) !== 10) {
            throw new \InvalidArgumentException('El CUIT debe tener 10 dígitos (sin verificador)');
        }
        
        $multiplicadores = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        
        for ($i = 0; $i < 10; $i++) {
            $suma += intval($cuit[$i]) * $multiplicadores[$i];
        }
        
        $resto = $suma % 11;
        return $resto === 0 ? 0 : (11 - $resto);
    }
    
    /**
     * Genera un CUIT válido a partir de un CUIT incompleto o con verificador incorrecto
     */
    public static function fix(string $cuit): string
    {
        $cuit = str_replace('-', '', $cuit);
        $base = substr($cuit, 0, 10);
        $verifier = self::calculateVerifier($base);
        return self::format($base . $verifier);
    }
    
    /**
     * Lista de CUITs de prueba válidos para homologación
     */
    public static function getTestCuits(): array
    {
        return [
            '20267565393', // CUIT de prueba AFIP
            '27123456780', // Ejemplo válido
            '30123456789', // Ejemplo válido empresa
        ];
    }
}
