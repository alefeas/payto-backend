<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->onDelete('cascade');
            $table->string('bank_name');
            $table->enum('account_type', ['corriente', 'caja_ahorro', 'cuenta_sueldo']);
            $table->string('cbu', 22);
            $table->string('alias')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
