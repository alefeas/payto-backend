<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Just make document_number nullable, skip constraint changes for fresh database
        Schema::table('clients', function (Blueprint $table) {
            $table->string('document_number')->nullable()->change();
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