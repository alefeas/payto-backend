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
        .items-table td { border-bottom: 1px solid #e2e8f0; padding: 4px; }
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
    </div>

    <div class="company-info">
        <h3 style="margin: 0 0 10px 0; color: #1e40af; font-size: 16px;">{{ $company->name }}</h3>
        <p style="margin: 5px 0;"><strong>CUIT:</strong> {{ $company->national_id }}</p>
        @if($address)
        <p style="margin: 5px 0;"><strong>Domicilio Comercial:</strong> {{ $address->street }} {{ $address->street_number }}
           @if($address->floor), Piso {{ $address->floor }}@endif
           @if($address->apartment) Dto {{ $address->apartment }}@endif</p>
        <p style="margin: 5px 0;">{{ $address->city ?? '' }}{{ $address->city && $address->province ? ', ' : '' }}{{ $address->province }} - CP {{ $address->postal_code }}</p>
        @endif
    </div>

    <div class="client-info">
        <h4 style="margin: 0 0 10px 0; color: #1e40af; font-size: 13px;">Datos del Cliente</h4>
        <p style="margin: 5px 0;"><strong>Razón Social:</strong> {{ $client->business_name ?? $client->name ?? ($client->first_name . ' ' . $client->last_name) }}</p>
        <p style="margin: 5px 0;"><strong>CUIT/DNI:</strong> {{ $client->document_number ?? $client->national_id }}</p>
        <p style="margin: 5px 0;"><strong>Condición IVA:</strong> 
        @php
            $taxConditions = [
                'registered_taxpayer' => 'Responsable Inscripto',
                'monotax' => 'Monotributista',
                'exempt' => 'Exento',
                'final_consumer' => 'Consumidor Final',
            ];
            echo $taxConditions[$client->tax_condition] ?? 'N/A';
        @endphp
        </p>
    </div>

    <p style="margin-bottom: 8px;"><strong>Emisión:</strong> {{ $invoice->issue_date->format('d/m/Y') }} | <strong>Vencimiento:</strong> {{ $invoice->due_date->format('d/m/Y') }}</p>

    <table class="items-table">
        <thead>
            <tr>
                <th>Descripción</th>
                <th style="width: 80px;">Cantidad</th>
                <th style="width: 100px;">Precio Unit.</th>
                <th style="width: 60px;">IVA %</th>
                <th style="width: 100px;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="text-right">{{ number_format($item->quantity, 2) }}</td>
                <td class="text-right">${{ number_format($item->unit_price, 2) }}</td>
                <td class="text-right">{{ $item->tax_rate }}%</td>
                <td class="text-right">${{ number_format($item->subtotal, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <p><strong>Subtotal:</strong> ${{ number_format($invoice->subtotal, 2) }}</p>
        <p><strong>IVA:</strong> ${{ number_format($invoice->total_taxes, 2) }}</p>
        @if($invoice->total_perceptions > 0)
        <p><strong>Percepciones:</strong> ${{ number_format($invoice->total_perceptions, 2) }}</p>
        @endif
        <h3><strong>TOTAL:</strong> ${{ number_format($invoice->total, 2) }}</h3>
    </div>

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
