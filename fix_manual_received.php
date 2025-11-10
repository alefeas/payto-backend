<?php
$file = 'app/Http/Controllers/Api/InvoiceController.php';
$content = file_get_contents($file);

// Buscar y reemplazar en storeManualReceived (línea ~1368)
$old = "            // Create items
            foreach (\$validated['items'] as \$index => \$item) {
                \$itemBase = \$item['quantity'] * \$item['unit_price'];
                \$itemDiscount = \$itemBase * ((\$item['discount_percentage'] ?? 0) / 100);

                \$itemSubtotal = \$itemBase - \$itemDiscount;
                \$itemTax = \$itemSubtotal * ((\$item['tax_rate'] ?? 0) / 100);


                \$invoice->items()->create([
                    'description' => \$item['description'],

                    'quantity' => \$item['quantity'],
                    'unit_price' => \$item['unit_price'],

                    'discount_percentage' => \$item['discount_percentage'] ?? 0,
                    'tax_rate' => \$item['tax_rate'] ?? 0,

                    'tax_category' => 'taxed',";

$new = "            // Create items
            foreach (\$validated['items'] as \$index => \$item) {
                \$taxRate = \$item['tax_rate'] ?? 0;
                \$itemBase = \$item['quantity'] * \$item['unit_price'];
                \$itemDiscount = \$itemBase * ((\$item['discount_percentage'] ?? 0) / 100);
                \$itemSubtotal = \$itemBase - \$itemDiscount;
                
                \$taxCategory = 'taxed';
                if (\$taxRate == -1) {
                    \$taxCategory = 'exempt';
                    \$taxRate = 0;
                } elseif (\$taxRate == -2) {
                    \$taxCategory = 'not_taxed';
                    \$taxRate = 0;
                }
                
                \$itemTax = (\$taxRate > 0) ? \$itemSubtotal * (\$taxRate / 100) : 0;

                \$invoice->items()->create([
                    'description' => \$item['description'],
                    'quantity' => \$item['quantity'],
                    'unit_price' => \$item['unit_price'],
                    'discount_percentage' => \$item['discount_percentage'] ?? 0,
                    'tax_rate' => \$taxRate,
                    'tax_category' => \$taxCategory,";

$content = str_replace($old, $new, $content);
file_put_contents($file, $content);
echo "Fixed storeManualReceived\n";
