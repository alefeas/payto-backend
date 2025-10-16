<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Allow Self-Signed Certificates
    |--------------------------------------------------------------------------
    |
    | ADVERTENCIA: Solo habilitar en desarrollo local para testing.
    | En producción SIEMPRE debe estar en false.
    | Los certificados autofirmados NO son válidos para AFIP.
    |
    */
    'allow_self_signed_certs' => env('AFIP_ALLOW_SELF_SIGNED', false),
    
    /*
    |--------------------------------------------------------------------------
    | Require CAE for Credit/Debit Notes
    |--------------------------------------------------------------------------
    |
    | Validar que la factura tenga CAE antes de permitir NC/ND.
    | false = Permite testing sin certificado AFIP
    | true = Requiere CAE válido (producción)
    |
    */
    'require_cae_for_notes' => env('AFIP_REQUIRE_CAE_FOR_NOTES', false),
];
