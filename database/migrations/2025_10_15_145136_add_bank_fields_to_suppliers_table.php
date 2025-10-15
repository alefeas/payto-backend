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
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('bank_name', 100)->nullable()->after('tax_condition');
            $table->enum('bank_account_type', ['CA', 'CC'])->nullable()->after('bank_name');
            $table->string('bank_account_number', 50)->nullable()->after('bank_account_type');
            $table->string('bank_cbu', 22)->nullable()->after('bank_account_number');
            $table->string('bank_alias', 50)->nullable()->after('bank_cbu');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bank_account_type', 'bank_account_number', 'bank_cbu', 'bank_alias']);
        });
    }
};
