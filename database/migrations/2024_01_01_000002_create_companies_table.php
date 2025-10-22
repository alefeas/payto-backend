<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('unique_id', 10)->unique()->nullable();
            $table->string('name', 200);
            $table->string('business_name', 200)->nullable();
            $table->string('national_id', 15);
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->text('logo_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('can_issue_invoices')->default(true);
            $table->string('deletion_code', 100);
            $table->string('invite_code', 50)->unique()->nullable();
            $table->string('default_role', 50)->default('operator');
            $table->string('tax_regime', 50)->default('registered_taxpayer');
            $table->enum('tax_condition', ['registered_taxpayer', 'monotax', 'exempt'])->default('registered_taxpayer');
            $table->string('gross_income_number', 20)->nullable();
            $table->date('activity_start_date')->nullable();
            $table->string('currency', 3)->default('ARS');
            $table->string('invoice_prefix', 10)->default('FC-001');
            $table->unsignedSmallInteger('default_sales_point')->default(1);
            $table->integer('last_invoice_number')->default(0);
            $table->integer('payment_terms')->default(30);
            $table->decimal('default_vat', 5, 2)->default(21.00);
            $table->decimal('default_gross_income', 5, 2)->default(2.50);
            $table->decimal('default_income_tax', 5, 2)->default(2.00);
            $table->decimal('default_gross_income_retention', 5, 2)->default(0.42);
            $table->decimal('default_social_security', 5, 2)->default(1.00);
            $table->boolean('email_notifications')->default(true);
            $table->boolean('payment_reminders')->default(true);
            $table->boolean('invoice_approvals')->default(false);
            $table->boolean('require_two_factor')->default(false);
            $table->integer('session_timeout')->default(60);
            $table->boolean('auto_generate_invites')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
