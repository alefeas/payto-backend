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
        Schema::table('payment_retentions', function (Blueprint $table) {
            // Drop existing foreign key if exists
            try {
                $table->dropForeign(['payment_id']);
            } catch (\Exception $e) {
                // Foreign key doesn't exist, continue
            }
            
            $table->unsignedBigInteger('payment_id')->change();
            $table->foreign('payment_id')->references('id')->on('invoice_payments_tracking')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_retentions', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('cascade');
        });
    }
};
