<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reglas de Validación AFIP
    |--------------------------------------------------------------------------
    |
    | Este archivo contiene las reglas de validación para la integración con AFIP.
    | Permite modificar reglas sin cambiar código, solo editando este archivo.
    |
    */

    // Tipos de comprobante permitidos por condición fiscal del emisor
    'voucher_compatibility' => [
        'responsable_inscripto' => [
            'allowed_vouchers' => ['1', '2', '3', '6', '7', '8', '11', '12', '13'],
            'description' => 'Responsable Inscripto puede emitir: Factura A, B, C y sus NC/ND',
        ],
        'monotributista' => [
            'allowed_vouchers' => ['6', '7', '8', '11', '12', '13', '51', '52', '53'],
            'description' => 'Monotributista puede emitir: Factura B, C, M y sus NC/ND',
        ],
        'exento' => [
            'allowed_vouchers' => ['11', '12', '13'],
            'description' => 'Exento puede emitir: Factura C y sus NC/ND',
        ],
    ],

    // Combinaciones válidas de emisor-receptor por tipo de comprobante
    'issuer_receiver_compatibility' => [
        // Factura A (código 1)
        '1' => [
            'allowed_receivers' => ['responsable_inscripto'],
            'description' => 'Factura A solo puede emitirse a Responsable Inscripto',
        ],
        // Factura B (código 6)
        '6' => [
            'allowed_receivers' => ['monotributista', 'exento', 'consumidor_final'],
            'description' => 'Factura B puede emitirse a Monotributista, Exento o Consumidor Final',
        ],
        // Factura C (código 11)
        '11' => [
            'allowed_receivers' => ['monotributista', 'exento', 'consumidor_final'],
            'description' => 'Factura C puede emitirse a Monotributista, Exento o Consumidor Final',
        ],
        // Factura M (código 51)
        '51' => [
            'allowed_receivers' => ['responsable_inscripto', 'monotributista', 'exento'],
            'description' => 'Factura M puede emitirse a RI, Monotributista o Exento (NO a Consumidor Final)',
        ],
    ],

    // Campos obligatorios según concepto
    'required_fields_by_concept' => [
        'products' => [
            'fields' => ['amount', 'voucher_type', 'issue_date'],
            'description' => 'Concepto Productos: campos básicos obligatorios',
        ],
        'services' => [
            'fields' => ['amount', 'voucher_type', 'issue_date', 'service_date_from', 'service_date_to'],
            'description' => 'Concepto Servicios: requiere fechas de servicio',
        ],
        'products_services' => [
            'fields' => ['amount', 'voucher_type', 'issue_date', 'service_date_from', 'service_date_to'],
            'description' => 'Concepto Productos y Servicios: requiere fechas de servicio',
        ],
    ],

    // Mensajes de error personalizados para códigos de AFIP
    // REMOVED: AFIP's original messages are more accurate
    'error_messages' => [],

    // Mensajes de validación personalizados (Laravel validation)
    'validation_messages' => [
        'voucher_type.required' => 'El tipo de comprobante es obligatorio',
        'voucher_type.in' => 'El tipo de comprobante seleccionado no es válido para su condición fiscal',
        'concept.required' => 'El concepto es obligatorio',
        'concept.in' => 'El concepto debe ser: products, services o products_services',
        'service_date_from.required_if' => 'La fecha de inicio del servicio es obligatoria para servicios',
        'service_date_to.required_if' => 'La fecha de fin del servicio es obligatoria para servicios',
        'service_date_to.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la fecha de inicio',
        'amount.required' => 'El importe es obligatorio',
        'amount.numeric' => 'El importe debe ser un número',
        'amount.min' => 'El importe debe ser mayor a cero',
        'issue_date.required' => 'La fecha de emisión es obligatoria',
        'issue_date.date' => 'La fecha de emisión no es válida',
        'sales_point.required' => 'El punto de venta es obligatorio',
        'sales_point.integer' => 'El punto de venta debe ser un número entero',
        'sales_point.min' => 'El punto de venta debe ser mayor a cero',
    ],

    // Mapeo de conceptos (string a número para AFIP)
    'concept_mapping' => [
        'products' => 1,
        'services' => 2,
        'products_services' => 3,
    ],

    // Mapeo inverso (número a string)
    'concept_reverse_mapping' => [
        1 => 'products',
        2 => 'services',
        3 => 'products_services',
    ],

    // Etiquetas de conceptos para mostrar al usuario
    'concept_labels' => [
        'products' => 'Productos',
        'services' => 'Servicios',
        'products_services' => 'Productos y Servicios',
        1 => 'Productos',
        2 => 'Servicios',
        3 => 'Productos y Servicios',
    ],
];
