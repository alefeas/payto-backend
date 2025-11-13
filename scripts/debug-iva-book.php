<?php

/**
 * Script para diagnosticar problemas en el libro IVA
 * Ejecutar: php artisan tinker
 * Luego: include 'scripts/debug-iva-book.php';
 */

use App\Models\Invoice;
use App\Models\Company;

function debugIvaBook($companyId, $month = null, $year = null) {
    $month = $month ?? date('n');
    $year = $year ?? date('Y');
    
    echo "🔍 Diagnosticando Libro IVA para empresa {$companyId} - {$month}/{$year}\n\n";
    
    $company = Company::find($companyId);
    if (!$company) {
        echo "❌ Empresa no encontrada\n";
        return;
    }
    
    echo "📋 Empresa: {$company->name} (CUIT: {$company->national_id})\n\n";
    
    // Facturas donde la empresa es EMISORA (VENTAS)
    $salesInvoices = Invoice::where('issuer_company_id', $companyId)
        ->whereYear('issue_date', $year)
        ->whereMonth('issue_date', $month)
        ->where('status', '!=', 'archived')
        ->with(['client', 'receiverCompany'])
        ->get();
    
    echo "📈 VENTAS (Facturas emitidas por la empresa):\n";
    echo "   Total: {$salesInvoices->count()} facturas\n";
    
    foreach ($salesInvoices as $invoice) {
        $client = $invoice->client ?? $invoice->receiverCompany;
        $clientName = $client ? ($client->name ?? $client->business_name ?? 'Sin nombre') : 'Sin cliente';
        echo "   - {$invoice->number} | {$clientName} | {$invoice->total} | Emisor: {$invoice->issuer_company_id} | Receptor: {$invoice->receiver_company_id}\n";
    }
    
    echo "\n";
    
    // Facturas donde la empresa es RECEPTORA (COMPRAS)
    $purchasesInvoices = Invoice::where(function($query) use ($companyId) {
            // Facturas recibidas de empresas conectadas
            $query->where('receiver_company_id', $companyId)
                  ->where('issuer_company_id', '!=', $companyId);
        })
        ->orWhere(function($query) use ($companyId) {
            // Facturas de proveedores externos
            $query->whereNotNull('supplier_id')
                  ->whereHas('supplier', function($q) use ($companyId) {
                      $q->where('company_id', $companyId);
                  });
        })
        ->whereYear('issue_date', $year)
        ->whereMonth('issue_date', $month)
        ->where('status', '!=', 'archived')
        ->with(['supplier', 'issuerCompany'])
        ->get();
    
    echo "📉 COMPRAS (Facturas recibidas por la empresa):\n";
    echo "   Total: {$purchasesInvoices->count()} facturas\n";
    
    foreach ($purchasesInvoices as $invoice) {
        $supplier = $invoice->supplier ?? $invoice->issuerCompany;
        $supplierName = $supplier ? ($supplier->name ?? $supplier->business_name ?? 'Sin nombre') : 'Sin proveedor';
        echo "   - {$invoice->number} | {$supplierName} | {$invoice->total} | Emisor: {$invoice->issuer_company_id} | Receptor: {$invoice->receiver_company_id}\n";
    }
    
    echo "\n";
    
    // Verificar facturas problemáticas
    $problematicInvoices = Invoice::where(function($query) use ($companyId) {
            // Facturas donde la empresa aparece como emisor Y receptor
            $query->where('issuer_company_id', $companyId)
                  ->where('receiver_company_id', $companyId);
        })
        ->whereYear('issue_date', $year)
        ->whereMonth('issue_date', $month)
        ->where('status', '!=', 'archived')
        ->get();
    
    if ($problematicInvoices->count() > 0) {
        echo "⚠️  FACTURAS PROBLEMÁTICAS (Emisor = Receptor):\n";
        foreach ($problematicInvoices as $invoice) {
            echo "   - {$invoice->number} | {$invoice->total} | Emisor: {$invoice->issuer_company_id} | Receptor: {$invoice->receiver_company_id}\n";
        }
        echo "\n";
    }
    
    // Facturas sin clasificar correctamente
    $unclassifiedInvoices = Invoice::where(function($query) use ($companyId) {
            // Facturas que involucran a la empresa pero no están bien clasificadas
            $query->where('issuer_company_id', $companyId)
                  ->orWhere('receiver_company_id', $companyId)
                  ->orWhereHas('supplier', function($q) use ($companyId) {
                      $q->where('company_id', $companyId);
                  });
        })
        ->whereYear('issue_date', $year)
        ->whereMonth('issue_date', $month)
        ->where('status', '!=', 'archived')
        ->whereNotIn('id', $salesInvoices->pluck('id')->merge($purchasesInvoices->pluck('id')))
        ->get();
    
    if ($unclassifiedInvoices->count() > 0) {
        echo "❓ FACTURAS SIN CLASIFICAR:\n";
        foreach ($unclassifiedInvoices as $invoice) {
            echo "   - {$invoice->number} | {$invoice->total} | Emisor: {$invoice->issuer_company_id} | Receptor: {$invoice->receiver_company_id} | Supplier: {$invoice->supplier_id}\n";
        }
    }
    
    echo "\n✅ Diagnóstico completado\n";
}

// Ejemplo de uso:
// debugIvaBook(1, 11, 2024); // Empresa 1, Noviembre 2024