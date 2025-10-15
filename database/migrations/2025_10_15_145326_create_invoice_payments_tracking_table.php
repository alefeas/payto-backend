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
        Schema::create('invoice_payments_tracking', function (Blueprint $table) {
            $table->id();
            $table->uuid('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->uuid('invoice_id');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->enum('payment_method', ['transfer', 'check', 'cash', 'card']);
            $table->string('reference_number', 100)->nullable();
            $table->string('attachment_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'in_process', 'confirmed', 'cancelled'])->default('pending');
            $table->uuid('registered_by');
            $table->foreign('registered_by')->references('id')->on('users');
            $table->timestamp('registered_at')->useCurrent();
            $table->uuid('confirmed_by')->nullable();
            $table->foreign('confirmed_by')->references('id')->on('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_payments_tracking');
    }
};
