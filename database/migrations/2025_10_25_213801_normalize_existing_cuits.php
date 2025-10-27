<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Normalize CUITs in companies table
        $companies = DB::table('companies')->get();
        foreach ($companies as $company) {
            $normalizedCuit = $this->formatCuitWithHyphens($company->national_id);
            if ($normalizedCuit !== $company->national_id) {
                DB::table('companies')
                    ->where('id', $company->id)
                    ->update(['national_id' => $normalizedCuit]);
            }
        }

        // Normalize CUITs in clients table
        $clients = DB::table('clients')->where('document_type', 'CUIT')->get();
        foreach ($clients as $client) {
            $normalizedCuit = $this->formatCuitWithHyphens($client->document_number);
            if ($normalizedCuit !== $client->document_number) {
                DB::table('clients')
                    ->where('id', $client->id)
                    ->update(['document_number' => $normalizedCuit]);
            }
        }

        // Normalize CUITs in suppliers table
        $suppliers = DB::table('suppliers')->where('document_type', 'CUIT')->get();
        foreach ($suppliers as $supplier) {
            $normalizedCuit = $this->formatCuitWithHyphens($supplier->document_number);
            if ($normalizedCuit !== $supplier->document_number) {
                DB::table('suppliers')
                    ->where('id', $supplier->id)
                    ->update(['document_number' => $normalizedCuit]);
            }
        }

        // Normalize receiver_document in invoices table
        $invoices = DB::table('invoices')->whereNotNull('receiver_document')->get();
        foreach ($invoices as $invoice) {
            $normalizedCuit = $this->formatCuitWithHyphens($invoice->receiver_document);
            if ($normalizedCuit !== $invoice->receiver_document) {
                DB::table('invoices')
                    ->where('id', $invoice->id)
                    ->update(['receiver_document' => $normalizedCuit]);
            }
        }
    }

    public function down(): void
    {
        // This migration cannot be reversed as we don't know the original format
    }

    private function formatCuitWithHyphens(string $cuit): string
    {
        // Remove existing hyphens
        $cleanCuit = str_replace('-', '', $cuit);
        
        // Add hyphens if CUIT has 11 digits
        if (strlen($cleanCuit) === 11 && ctype_digit($cleanCuit)) {
            return substr($cleanCuit, 0, 2) . '-' . substr($cleanCuit, 2, 8) . '-' . substr($cleanCuit, 10, 1);
        }
        
        // Return as-is if not a valid 11-digit CUIT
        return $cuit;
    }
};