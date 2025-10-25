<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Drop the existing unique constraint
            $table->dropUnique(['company_id', 'document_number']);
            
            // Make document_number nullable
            $table->string('document_number')->nullable()->change();
            
            // Add new unique constraint that handles nulls properly
            // This will allow multiple null values but enforce uniqueness for non-null values
            $table->unique(['company_id', 'document_number'], 'clients_company_document_unique');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Drop the new unique constraint
            $table->dropUnique('clients_company_document_unique');
            
            // Make document_number not nullable again
            $table->string('document_number')->nullable(false)->change();
            
            // Restore the original unique constraint
            $table->unique(['company_id', 'document_number']);
        });
    }
};