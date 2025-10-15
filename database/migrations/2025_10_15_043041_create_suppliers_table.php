<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->enum('document_type', ['CUIT', 'CUIL', 'DNI', 'Pasaporte', 'CDI']);
            $table->string('document_number', 20);
            $table->string('business_name', 100)->nullable();
            $table->string('first_name', 50)->nullable();
            $table->string('last_name', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address', 200)->nullable();
            $table->enum('tax_condition', ['registered_taxpayer', 'monotax', 'exempt', 'final_consumer']);
            $table->timestamps();
            
            $table->unique(['company_id', 'document_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
