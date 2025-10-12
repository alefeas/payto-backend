<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_billing_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->unique();
            $table->decimal('default_vat', 5, 2)->default(21.00);
            $table->decimal('vat_perception', 5, 2)->default(0.00);
            $table->decimal('gross_income_perception', 5, 2)->default(2.50);
            $table->decimal('social_security_perception', 5, 2)->default(1.00);
            $table->decimal('vat_retention', 5, 2)->default(0.00);
            $table->decimal('income_tax_retention', 5, 2)->default(2.00);
            $table->decimal('gross_income_retention', 5, 2)->default(0.42);
            $table->decimal('social_security_retention', 5, 2)->default(0.00);
            $table->timestamps();
            
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_billing_settings');
    }
};
