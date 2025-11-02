<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->type }} {{ $invoice->number }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; color: #333; margin: 0; padding: 10px; }
        .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg); font-size: 60px; color: rgba(59, 130, 246, 0.05); font-weight: bold; z-index: -1; }
        .header { text-align: center; border: 2px solid #3b82f6; padding: 8px; margin-bottom: 10px; background: #f0f9ff; }
        .tipo { font-size: 40px; font-weight: bold; border: 3px solid #3b82f6; padding: 5px 20px; display: inline-block; color: #3b82f6; }
        .company-info { border-left: 3px solid #3b82f6; padding: 8px; margin-bottom: 10px; background: #f8fafc; }
        .client-info { border: 1px solid #cbd5e1; padding: 8px; margin-bottom: 10px; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9px; }
        .items-table th { background: #3b82f6; color: white; padding: 6px 4px; text-align: left; }
        .items-table th.text-right { text-align: right; }
        .items-table td { border-bottom: 1px solid #e2e8f0; padding: 4px; }
        .iva-breakdown { width: 50%; margin-left: auto; font-size: 9px; border: 1px solid #cbd5e1; margin-bottom: 10px; }
        .iva-breakdown th { background: #f1f5f9; padding: 4px; text-align: left; font-weight: bold; }
        .iva-breakdown td { padding: 4px; border-bottom: 1px solid #e2e8f0; }
        .notes-box { border: 1px solid #cbd5e1; padding: 8px; margin-bottom: 10px; background: #fefce8; font-size: 9px; }
        .payment-info { border: 1px solid #cbd5e1; padding: 8px; margin-bottom: 10px; background: #f0fdf4; font-size: 9px; }
        .totals { text-align: right; padding: 8px; background: #f8fafc; }
        .cae-box { border: 2px solid #3b82f6; padding: 8px; margin-top: 10px; background: #eff6ff; font-size: 9px; }
        .text-right { text-align: right; }
        .payto-footer { text-align: center; margin-top: 10px; padding-top: 8px; border-top: 1px solid #cbd5e1; color: #64748b; font-size: 8px; }
        .payto-logo { color: #3b82f6; font-weight: bold; font-size: 10px; }
        h3, h4 { margin: 0 0 5px 0; }
        p { margin: 3px 0; }
    </style>
</head>
<body>
    <div class="watermark">PayTo</div>
    <div class="header">
        <div class="tipo">{{ substr($invoice->type, 0, 1) }}</div>
        <h2>{{ $invoice->type === 'NCA' || $invoice->type === 'NCB' || $invoice->type === 'NCC' ? 'NOTA DE CRÉDITO ELECTRÓNICA' : 
               ($invoice->type === 'NDA' || $invoice->type === 'NDB' || $invoice->type === 'NDC' ? 'NOTA DE DÉBITO ELECTRÓNICA' : 'FACTURA ELECTRÓNICA') }}</h2>
        <p><strong>Nº {{ $invoice->number }}</strong></p>
        <p style="font-size: 10px; margin-top: 5px;">(Comprobante Autorizado por AFIP)</p>
        @if($invoice->synced_from_afip)
        <div style="background: #fef3c7; border: 1px solid #fbbf24; padding: 6px; margin-top: 8px; border-radius: 4px;">
            <p style="font-size: 9px; margin: 0; color: #92400e;">
                <strong>AVISO:</strong> Comprobante reconstruido desde datos de AFIP/ARCA.<br>
                Información disponible: totales, CAE y fecha. Para el PDF oficial, solicitarlo al emisor.
            </p>
        </div>
        @endif
    </div>

    <div class="company-info">
        <h3 style="margin: 0 0 10px 0; color: #1e40af; font-size: 16px;">{{ $company->name }}</h3>
        <p style="margin: 5px 0;"><strong>CUIT:</strong> {{ $company->national_id }}</p>
        <p style="margin: 5px 0;"><strong>Domicilio Comercial:</strong> 
        @if($address && $address->street)
            {{ $address->street }} {{ $address->street_number ?? '' }}
            @if($address->floor), Piso {{ $address->floor }}@endif
            @if($address->apartment) Dto {{ $address->apartment }}@endif
        @else
            <span style="color: #94a3b8;">No especificado</span>
        @endif
        </p>
        <p style="margin: 5px 0;">
        @if($address && ($address->city || $address->province || $address->postal_code))
            @if($address->city){{ $address->city }}@endif
            @if($address->city && $address->province), @endif
            @if($address->province){{ $address->province }}@endif
            @if($address->postal_code) - CP {{ $address->postal_code }}@endif
            @if(!$address->city && !$address->province && !$address->postal_code)
                <span style="color: #94a3b8;">No especificado</span>
            @endif
        @else
            <span style="color: #94a3b8;">No especificado</span>
        @endif
        </p>
    </div>

    <div class="client-info">
        <h4 style="margin: 0 0 10px 0; color: #1e40af; font-size: 13px;">Datos del Cliente</h4>
        <p style="margin: 5px 0;"><strong>Razón Social:</strong> 
        @if($client)
            {{ $client->business_name ?? $client->name ?? ($client->first_name && $client->last_name ? $client->first_name . ' ' . $client->last_name : '') }}
        @elseif($invoice->receiver_name)
            {{ $invoice->receiver_name }}
        @else
            <span style="color: #94a3b8;">No especificado</span>
        @endif
        </p>
        <p style="margin: 5px 0;"><strong>CUIT:</strong> 
            @if($client)
                {{ $client->document_number ?? $client->national_id ?? 'No especificado' }}
            @elseif($invoice->receiver_document)
                {{ $invoice->receiver_document }}
            @else
                <span style="color: #94a3b8;">No especificado</span>
            @endif
        </p>
        <p style="margin: 5px 0;"><strong>Condición IVA:</strong> 
        @if($client && $client->tax_condition)
            @php
                $taxConditions = [
                    'registered_taxpayer' => 'Responsable Inscripto',
                    'monotax' => 'Monotributista',
                    'exempt' => 'Exento',
                    'final_consumer' => 'Consumidor Final',
                ];
                echo $taxConditions[$client->tax_condition] ?? 'No especificado';
            @endphp
        @else
            <span style="color: #94a3b8;">No especificado</span>
        @endif
        </p>
        @if($client)
            @php
                // Manejar address que puede ser string o objeto
                $clientAddress = null;
                if (isset($client->address)) {
                    if (is_string($client->address)) {
                        $clientAddress = $client->address;
                    } elseif (is_object($client->address) && isset($client->address->street)) {
                        // Si es un objeto Address, construir la dirección
                        $clientAddress = trim(($client->address->street ?? '') . ' ' . ($client->address->street_number ?? ''));
                    }
                }
                $clientCity = $client->city ?? (is_object($client->address ?? null) ? $client->address->city ?? null : null);
                $clientProvince = $client->province ?? (is_object($client->address ?? null) ? $client->address->province ?? null : null);
                $clientPostalCode = $client->postal_code ?? (is_object($client->address ?? null) ? $client->address->postal_code ?? null : null);
            @endphp
            @if($clientAddress || $clientCity || $clientProvince || $clientPostalCode)
            <p style="margin: 5px 0;"><strong>Domicilio:</strong> 
                @if($clientAddress)
                    {{ $clientAddress }}
                @else
                    <span style="color: #94a3b8;">No especificado</span>
                @endif
            </p>
            <p style="margin: 5px 0;">
                @if($clientCity || $clientProvince || $clientPostalCode)
                    @if($clientCity){{ $clientCity }}@endif
                    @if($clientCity && $clientProvince), @endif
                    @if($clientProvince){{ $clientProvince }}@endif
                    @if($clientPostalCode) - CP {{ $clientPostalCode }}@endif
                    @if(!$clientCity && !$clientProvince && !$clientPostalCode)
                        <span style="color: #94a3b8;">No especificado</span>
                    @endif
                @else
                    <span style="color: #94a3b8;">No especificado</span>
                @endif
            </p>
            @endif
        @endif
    </div>

    <p style="margin-bottom: 8px;">
        <strong>Emisión:</strong> {{ $invoice->issue_date->format('d/m/Y') }} | 
        <strong>Vencimiento:</strong> {{ $invoice->due_date->format('d/m/Y') }} | 
        <strong>Concepto:</strong> 
        @php
            $conceptMap = [
                'products' => 'Productos',
                'services' => 'Servicios',
                'products_services' => 'Productos y Servicios',
                1 => 'Productos',
                2 => 'Servicios',
                3 => 'Productos y Servicios',
            ];
            echo $conceptMap[$invoice->concept] ?? 'Productos';
        @endphp
        @if(in_array($invoice->concept, ['services', 'products_services', 2, 3]) && $invoice->service_date_from && $invoice->service_date_to)
            <br><strong>Período de Servicio:</strong> {{ $invoice->service_date_from->format('d/m/Y') }} al {{ $invoice->service_date_to->format('d/m/Y') }}
        @endif
    </p>

    <table class="items-table">
        <thead>
            <tr>
                <th>Descripción</th>
                <th class="text-right" style="width: 70px;">Cantidad</th>
                <th class="text-right" style="width: 90px;">Precio Unit.</th>
                <th class="text-right" style="width: 60px;">Bonif. %</th>
                <th class="text-right" style="width: 50px;">IVA %</th>
                <th class="text-right" style="width: 90px;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="text-right">{{ number_format($item->quantity, 2) }}</td>
                <td class="text-right">${{ number_format($item->unit_price, 2) }}</td>
                <td class="text-right">{{ $item->discount_percentage > 0 ? number_format($item->discount_percentage, 2) . '%' : '-' }}</td>
                <td class="text-right">
                    @if($item->tax_rate == -1)
                        Exento
                    @elseif($item->tax_rate == -2)
                        No Grav.
                    @else
                        {{ number_format($item->tax_rate, 2) }}%
                    @endif
                </td>
                <td class="text-right">${{ number_format($item->subtotal, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @php
        // Group items by tax rate for IVA breakdown
        $ivaBreakdown = [];
        foreach($invoice->items as $item) {
            $rate = $item->tax_rate;
            if ($rate > 0) {
                if (!isset($ivaBreakdown[$rate])) {
                    $ivaBreakdown[$rate] = ['base' => 0, 'tax' => 0];
                }
                $ivaBreakdown[$rate]['base'] += $item->subtotal;
                $ivaBreakdown[$rate]['tax'] += $item->tax_amount;
            }
        }
    @endphp

    @if(count($ivaBreakdown) > 0)
    <table class="iva-breakdown">
        <thead>
            <tr>
                <th style="width: 30%; text-align: left;">Alícuota</th>
                <th style="width: 35%; text-align: right;">Base Imponible</th>
                <th style="width: 35%; text-align: right;">IVA</th>
            </tr>
        </thead>
        <tbody>
            @foreach($ivaBreakdown as $rate => $amounts)
            <tr>
                <td style="text-align: left;">IVA {{ number_format($rate, 2) }}%</td>
                <td style="text-align: right;">${{ number_format($amounts['base'], 2) }}</td>
                <td style="text-align: right;">${{ number_format($amounts['tax'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if($invoice->perceptions && $invoice->perceptions->count() > 0)
    <table class="iva-breakdown" style="width: 100%; margin-bottom: 10px;">
        <thead>
            <tr>
                <th style="width: 40%; text-align: left;">Percepción</th>
                <th style="width: 15%; text-align: right;">Alícuota</th>
                <th style="width: 25%; text-align: right;">Base Imponible</th>
                <th style="width: 20%; text-align: right;">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->perceptions as $perception)
            <tr>
                <td style="text-align: left;">{{ $perception->name }}</td>
                <td style="text-align: right;">{{ number_format($perception->rate, 2) }}%</td>
                <td style="text-align: right;">${{ number_format($perception->base_amount, 2) }}</td>
                <td style="text-align: right;"><strong>${{ number_format($perception->amount, 2) }}</strong></td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="totals">
        <p><strong>Subtotal:</strong> ${{ number_format($invoice->subtotal, 2) }}</p>
        <p><strong>IVA:</strong> ${{ number_format($invoice->total_taxes, 2) }}</p>
        @if($invoice->total_perceptions > 0)
        <p><strong>Total Percepciones:</strong> ${{ number_format($invoice->total_perceptions, 2) }}</p>
        @endif
        <h3><strong>TOTAL:</strong> ${{ number_format($invoice->total, 2) }}</h3>
    </div>

    @if($invoice->notes)
    <div class="notes-box">
        <h4 style="margin: 0 0 5px 0; font-size: 10px;">Observaciones:</h4>
        <p style="margin: 0;">{{ $invoice->notes }}</p>
    </div>
    @endif

    @php
        // Solo mostrar datos bancarios en facturas de venta (no en NC/ND)
        $isInvoice = !in_array($invoice->type, ['NCA', 'NCB', 'NCC', 'NCM', 'NCE', 'NDA', 'NDB', 'NDC', 'NDM', 'NDE']);
        
        // Determinar de quién mostrar los datos bancarios
        $bankAccountsOwner = null;
        $bankAccounts = null;
        
        if ($isInvoice) {
            // Para facturas emitidas, mostrar datos del emisor (company)
            // Para facturas recibidas, mostrar datos del proveedor (supplier/issuerCompany)
            if ($invoice->is_manual_load && $invoice->supplier) {
                // Factura recibida: mostrar datos del proveedor si tiene
                $bankAccountsOwner = $invoice->supplier;
                $bankAccounts = $invoice->supplier->bankAccounts ?? null;
            } elseif ($company && $company->bankAccounts) {
                // Factura emitida: mostrar datos del emisor
                $bankAccountsOwner = $company;
                $bankAccounts = $company->bankAccounts;
            }
        }
    @endphp
    @if($bankAccounts && $bankAccounts->count() > 0)
    @php
        $primaryAccount = $bankAccounts->where('is_primary', true)->first() ?? $bankAccounts->first();
    @endphp
    <div class="payment-info">
        <h4 style="margin: 0 0 5px 0; font-size: 10px;">Datos para Transferencia Bancaria:</h4>
        <p style="margin: 2px 0;"><strong>Banco:</strong> {{ $primaryAccount->bank_name }}</p>
        <p style="margin: 2px 0;"><strong>CBU:</strong> {{ $primaryAccount->cbu }}</p>
        @if($primaryAccount->alias)
        <p style="margin: 2px 0;"><strong>Alias:</strong> {{ $primaryAccount->alias }}</p>
        @endif
        <p style="margin: 2px 0;"><strong>Titular:</strong> {{ $bankAccountsOwner->business_name ?? $bankAccountsOwner->name ?? ($bankAccountsOwner->first_name && $bankAccountsOwner->last_name ? $bankAccountsOwner->first_name . ' ' . $bankAccountsOwner->last_name : 'No especificado') }}</p>
    </div>
    @endif

    @if($invoice->afip_cae)
    <div class="cae-box">
        <div style="display: table; width: 100%;">
            <div style="display: table-cell; width: 70%; vertical-align: top;">
                <h3 style="margin: 0 0 10px 0; font-size: 16px;">COMPROBANTE AUTORIZADO ELECTRÓNICAMENTE</h3>
                @if($barcodeBase64)
                <div style="margin: 10px 0;">
                    <img src="data:image/png;base64,{{ $barcodeBase64 }}" alt="Código de barras AFIP" style="max-width: 100%; height: auto;" />
                </div>
                @endif
                <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                    <tr>
                        <td style="padding: 3px;"><strong>CAE N°:</strong></td>
                        <td style="padding: 3px; font-family: monospace; font-size: 13px;">{{ $invoice->afip_cae }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 3px;"><strong>Fecha de Vto. de CAE:</strong></td>
                        <td style="padding: 3px;">{{ $invoice->afip_cae_due_date->format('d/m/Y') }}</td>
                    </tr>
                </table>
            </div>
            @if($qrBase64)
            <div style="display: table-cell; width: 30%; text-align: center; vertical-align: top;">
                <p style="font-size: 10px; margin: 0 0 5px 0;"><strong>Código QR</strong></p>
                <img src="data:image/svg+xml;base64,{{ $qrBase64 }}" alt="Código QR AFIP" style="width: 120px; height: 120px;" />
            </div>
            @endif
        </div>
        <p style="text-align: center; font-size: 9px; margin-top: 10px; color: #666; border-top: 1px solid #ccc; padding-top: 8px;">
            Comprobante Autorizado Electrónicamente - Verificable en www.afip.gob.ar<br>
            Administración Federal de Ingresos Públicos
        </p>
    </div>
    @endif

    <div class="payto-footer">
        <span class="payto-logo">PayTo</span> - Facturación Electrónica | Este comprobante fue generado con PayTo
    </div>
</body>
</html>
