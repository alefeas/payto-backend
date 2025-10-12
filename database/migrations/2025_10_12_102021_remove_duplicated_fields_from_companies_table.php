<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Campos movidos a company_billing_settings
            $table->dropColumn([
                'default_vat',
                'default_gross_income',
                'default_income_tax',
                'default_gross_income_retention',
                'default_social_security',
            ]);
            
            // Campos movidos a company_preferences
            $table->dropColumn([
                'currency',
                'payment_terms',
                'email_notifications',
                'payment_reminders',
                'invoice_approvals',
                'require_two_factor',
                'session_timeout',
                'auto_generate_invites',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Restaurar campos de billing
            $table->decimal('default_vat', 5, 2)->default(21.00);
            $table->decimal('default_gross_income', 5, 2)->default(2.50);
            $table->decimal('default_income_tax', 5, 2)->default(2.00);
            $table->decimal('default_gross_income_retention', 5, 2)->default(0.42);
            $table->decimal('default_social_security', 5, 2)->default(1.00);
            
            // Restaurar campos de preferences
            $table->string('currency', 3)->default('ARS');
            $table->integer('payment_terms')->default(30);
            $table->boolean('email_notifications')->default(true);
            $table->boolean('payment_reminders')->default(true);
            $table->boolean('invoice_approvals')->default(false);
            $table->boolean('require_two_factor')->default(false);
            $table->integer('session_timeout')->default(60);
            $table->boolean('auto_generate_invites')->default(true);
        });
    }
};
