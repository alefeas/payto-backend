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
        if (!Schema::hasTable('invoice_payments')) {
            Schema::create('invoice_payments', function (Blueprint $table) {
                $table->id();
                $table->timestamps();
            });
        }

        Schema::table('invoice_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_payments', 'invoice_id')) {
                $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            }
            if (!Schema::hasColumn('invoice_payments', 'amount')) {
                $table->decimal('amount', 15, 2);
            }
            if (!Schema::hasColumn('invoice_payments', 'payment_date')) {
                $table->date('payment_date');
            }
            if (!Schema::hasColumn('invoice_payments', 'payment_method')) {
                $table->string('payment_method')->nullable();
            }
            if (!Schema::hasColumn('invoice_payments', 'notes')) {
                $table->text('notes')->nullable();
            }
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
