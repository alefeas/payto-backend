<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePerception;
use Illuminate\Console\Command;

class CreateComplexTestInvoice extends Command
{
    protected $signature = 'test:create-complex-invoice {company_id} {client_id} {user_id}';
    protected $description = 'Create a complex test invoice with multiple IVA rates, discounts, and perceptions';

    public function handle()
    {
        $companyId = $this->argument('company_id');
        $clientId = $this->argument('client_id');
        $userId = $this->argument('user_id');

        $company = Company::find($companyId);
        $client = Client::find($clientId);

        if (!$company) {
            $this->error("Company not found");
            return 1;
        }

        if (!$client) {
            $this->error("Client not found");
            return 1;
        }

        $this->info("Creating complex test invoice...");

        // Crear factura
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'issuer_company_id' => $company->id,
            'client_id' => $client->id,
            'created_by' => $userId,
            'type' => 'A',
            'sales_point' => 1,
            'voucher_number' => 0,
            'number' => '0001-00000000',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'concept' => 'products',
            'status' => 'approved',
            'subtotal' => 0,
            'total_taxes' => 0,
            'total_perceptions' => 0,
            'total' => 0,
        ]);

        $items = [
            ['desc' => 'Producto IVA 21% con 10% descuento', 'qty' => 2.5, 'price' => 100.00, 'disc' => 10, 'tax' => 21],
            ['desc' => 'Producto IVA 10.5%', 'qty' => 3, 'price' => 150.00, 'disc' => 0, 'tax' => 10.5],
            ['desc' => 'Producto IVA 5% con 25% descuento', 'qty' => 1, 'price' => 200.00, 'disc' => 25, 'tax' => 5],
            ['desc' => 'Producto IVA 2.5% cantidad decimal', 'qty' => 1.75, 'price' => 123.45, 'disc' => 0, 'tax' => 2.5],
            ['desc' => 'Producto IVA 0%', 'qty' => 1, 'price' => 50.00, 'disc' => 0, 'tax' => 0],
            ['desc' => 'Producto IVA 21% con 50% descuento', 'qty' => 4, 'price' => 87.33, 'disc' => 50, 'tax' => 21],
            ['desc' => 'Producto monto pequeño', 'qty' => 1, 'price' => 0.10, 'disc' => 0, 'tax' => 21],
        ];

        foreach ($items as $index => $item) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $item['desc'],
                'quantity' => $item['qty'],
                'unit_price' => $item['price'],
                'subtotal' => round($item['qty'] * $item['price'] * (1 - $item['disc'] / 100), 2),
                'tax_rate' => $item['tax'],
                'discount_percentage' => $item['disc'],
                'order_index' => $index,
            ]);
        }

        // Calcular totales
        $subtotal = 0;
        $totalTaxes = 0;

        foreach ($invoice->items as $item) {
            $itemBase = $item->quantity * $item->unit_price;
            $discount = ($item->discount_percentage ?? 0) / 100;
            $itemSubtotal = $itemBase * (1 - $discount);
            
            if ($item->tax_rate > 0) {
                $itemTax = $itemSubtotal * $item->tax_rate / 100;
                $totalTaxes += $itemTax;
            }
            
            $subtotal += $itemSubtotal;
        }

        // Agregar percepciones
        $perceptionIVA = InvoicePerception::create([
            'invoice_id' => $invoice->id,
            'type' => 'vat_perception',
            'name' => 'Percepción IVA',
            'rate' => 3,
            'base_amount' => $subtotal,
            'amount' => round($subtotal * 0.03, 2),
        ]);

        $perceptionIIBB = InvoicePerception::create([
            'invoice_id' => $invoice->id,
            'type' => 'gross_income_perception',
            'name' => 'Percepción IIBB',
            'rate' => 2.5,
            'base_amount' => $subtotal,
            'amount' => round($subtotal * 0.025, 2),
        ]);

        $totalPerceptions = $perceptionIVA->amount + $perceptionIIBB->amount;

        // Actualizar totales
        $invoice->update([
            'subtotal' => round($subtotal, 2),
            'total_taxes' => round($totalTaxes, 2),
            'total_perceptions' => round($totalPerceptions, 2),
            'total' => round($subtotal + $totalTaxes + $totalPerceptions, 2),
        ]);

        $this->info("✓ Complex test invoice created successfully!");
        $this->info("Invoice ID: {$invoice->id}");
        $this->info("Subtotal: $" . number_format($invoice->subtotal, 2));
        $this->info("Total Taxes: $" . number_format($invoice->total_taxes, 2));
        $this->info("Total Perceptions: $" . number_format($invoice->total_perceptions, 2));
        $this->info("Total: $" . number_format($invoice->total, 2));
        $this->newLine();
        $this->info("Items breakdown:");
        
        foreach ($invoice->items as $item) {
            $itemBase = $item->quantity * $item->unit_price;
            $discount = ($item->discount_percentage ?? 0) / 100;
            $itemSubtotal = $itemBase * (1 - $discount);
            $itemTax = $item->tax_rate > 0 ? $itemSubtotal * $item->tax_rate / 100 : 0;
            
            $this->line("  - {$item->description}");
            $this->line("    Qty: {$item->quantity} x \${$item->unit_price} | Discount: {$item->discount_percentage}% | IVA: {$item->tax_rate}%");
            $this->line("    Subtotal: $" . number_format($itemSubtotal, 2) . " | Tax: $" . number_format($itemTax, 2));
        }

        return 0;
    }
}
