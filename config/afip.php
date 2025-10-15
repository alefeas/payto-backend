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
];
