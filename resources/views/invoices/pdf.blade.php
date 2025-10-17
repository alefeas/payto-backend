<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->type }} {{ $invoice->number }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .header { text-align: center; border: 2px solid #000; padding: 10px; margin-bottom: 20px; }
        .tipo { font-size: 48px; font-weight: bold; border: 3px solid #000; padding: 5px 20px; display: inline-block; }
        .company-info { margin-bottom: 20px; }
        .client-info { border: 1px solid #000; padding: 10px; margin-bottom: 20px; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table th, .items-table td { border: 1px solid #000; padding: 5px; text-align: left; }
        .items-table th { background-color: #f0f0f0; }
        .totals { text-align: right; }
        .cae-box { border: 2px solid #000; padding: 10px; margin-top: 20px; background-color: #f9f9f9; }
        .text-right { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <div class="tipo">{{ substr($invoice->type, 0, 1) }}</div>
        <h2>{{ $invoice->type === 'NCA' || $invoice->type === 'NCB' || $invoice->type === 'NCC' ? 'NOTA DE CRÉDITO' : 
               ($invoice->type === 'NDA' || $invoice->type === 'NDB' || $invoice->type === 'NDC' ? 'NOTA DE DÉBITO' : 'FACTURA') }}</h2>
        <p><strong>Nº {{ $invoice->number }}</strong></p>
    </div>

    <div class="company-info">
        <h3>{{ $company->name }}</h3>
        <p>CUIT: {{ $company->national_id }}</p>
        @if($address)
        <p>{{ $address->street }} {{ $address->street_number }}
           @if($address->floor), Piso {{ $address->floor }}@endif
           @if($address->apartment) Dto {{ $address->apartment }}@endif</p>
        <p>{{ $address->postal_code }} - {{ $address->province }}</p>
        @endif
    </div>

    <div class="client-info">
        <p><strong>Cliente:</strong> {{ $client->business_name ?? $client->name ?? ($client->first_name . ' ' . $client->last_name) }}</p>
        <p><strong>CUIT/DNI:</strong> {{ $client->document_number ?? $client->national_id }}</p>
        <p><strong>Condición IVA:</strong> {{ $client->tax_condition ?? 'N/A' }}</p>
    </div>

    <p><strong>Fecha de Emisión:</strong> {{ $invoice->issue_date->format('d/m/Y') }}</p>
    <p><strong>Fecha de Vencimiento:</strong> {{ $invoice->due_date->format('d/m/Y') }}</p>

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
        <p><strong>CAE:</strong> {{ $invoice->afip_cae }}</p>
        <p><strong>Vencimiento CAE:</strong> {{ $invoice->afip_cae_due_date->format('d/m/Y') }}</p>
        <p><strong>Comprobante Autorizado por AFIP</strong></p>
    </div>
    @endif
</body>
</html>
