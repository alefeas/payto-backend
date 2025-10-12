<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->unique();
            $table->string('currency', 3)->default('ARS');
            $table->integer('payment_terms')->default(30);
            $table->boolean('email_notifications')->default(true);
            $table->boolean('payment_reminders')->default(true);
            $table->boolean('invoice_approvals')->default(false);
            $table->boolean('require_two_factor')->default(false);
            $table->integer('session_timeout')->default(60);
            $table->boolean('auto_generate_invites')->default(true);
            $table->timestamps();
            
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_preferences');
    }
};
