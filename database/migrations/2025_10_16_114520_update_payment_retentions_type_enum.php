<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE payment_retentions MODIFY COLUMN type ENUM('vat_retention','income_tax_retention','gross_income_retention','social_security_retention','suss_retention') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE payment_retentions MODIFY COLUMN type ENUM('vat_retention','income_tax_retention','gross_income_retention','social_security_retention') NOT NULL");
    }
};
