<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();

$ncndTypes = ['NCA', 'NCB', 'NCC', 'NCM', 'NCE', 'NDA', 'NDB', 'NDC', 'NDM', 'NDE'];

$ncndInvoices = Invoice::whereIn('type', $ncndTypes)
    ->whereNotNull('related_invoice_id')
    ->with('relatedInvoice')
    ->get();

echo "Encontradas " . $ncndInvoices->count() . " NC/ND con factura relacionada\n\n";

$updated = 0;
foreach ($ncndInvoices as $ncnd) {
    if (!$ncnd->relatedInvoice) {
        echo "⚠️  NC/ND {$ncnd->number} - Factura relacionada no existe\n";
        continue;
    }
    
    $oldStatus = $ncnd->status;
    $oldAfipStatus = $ncnd->afip_status;
    $newStatus = $ncnd->relatedInvoice->status;
    $newAfipStatus = $ncnd->relatedInvoice->afip_status;
    
    if ($oldStatus !== $newStatus || $oldAfipStatus !== $newAfipStatus) {
        $ncnd->status = $newStatus;
        $ncnd->afip_status = $newAfipStatus;
        $ncnd->save();
        
        echo "✓ NC/ND {$ncnd->number}: status '{$oldStatus}' → '{$newStatus}', afip_status '{$oldAfipStatus}' → '{$newAfipStatus}'\n";
        $updated++;
    }
}

DB::commit();

echo "\n✓ Actualizadas {$updated} NC/ND\n";
