<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('tax_condition', ['registered_taxpayer', 'monotax', 'exempt', 'final_consumer'])->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('tax_condition', ['registered_taxpayer', 'monotax', 'exempt'])->default('registered_taxpayer')->change();
        });
    }
};
