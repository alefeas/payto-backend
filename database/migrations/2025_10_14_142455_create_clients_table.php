<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->onDelete('cascade');
            $table->enum('document_type', ['CUIT', 'CUIL', 'DNI', 'Pasaporte', 'CDI']);
            $table->string('document_number');
            $table->string('business_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->enum('tax_condition', ['registered_taxpayer', 'monotax', 'exempt', 'final_consumer']);
            $table->timestamps();
            
            $table->unique(['company_id', 'document_number']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
