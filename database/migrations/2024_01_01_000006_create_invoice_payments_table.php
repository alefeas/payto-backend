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
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('invoice_id', 36)->index('invoice_payments_invoice_id_foreign');
            $table->decimal('amount', 15);
            $table->date('payment_date');
            $table->enum('payment_method', ['transfer', 'cash', 'check', 'debit_card', 'credit_card', 'mercadopago', 'other']);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->char('created_by', 36)->nullable()->index('invoice_payments_created_by_foreign');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};
