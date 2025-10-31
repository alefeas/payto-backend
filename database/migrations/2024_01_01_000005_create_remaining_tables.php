<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company Connections
        Schema::create('company_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('connected_company_id');
            $table->enum('status', ['connected', 'pending_sent', 'pending_received', 'blocked']);
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('connected_at')->nullable();
            $table->uuid('requested_by');
            $table->text('message')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->unique(['company_id', 'connected_company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('connected_company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('requested_by')->references('id')->on('users');
            $table->index(['company_id', 'status']);
        });

        // Suppliers
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('company_id');
            $table->enum('document_type', ['CUIT', 'CUIL', 'DNI', 'Pasaporte', 'CDI']);
            $table->string('document_number', 20);
            $table->string('business_name', 100)->nullable();
            $table->string('first_name', 50)->nullable();
            $table->string('last_name', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address', 200)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->enum('tax_condition', ['registered_taxpayer', 'monotax', 'exempt', 'final_consumer']);
            $table->string('bank_name', 100)->nullable();
            $table->enum('bank_account_type', ['CA', 'CC'])->nullable();
            $table->string('bank_account_number', 50)->nullable();
            $table->string('bank_cbu', 22)->nullable();
            $table->string('bank_alias', 50)->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->unique(['company_id', 'document_number']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });

        // Clients
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->enum('document_type', ['CUIT', 'CUIL', 'DNI', 'passport', 'CDI']);
            $table->string('document_number', 15);
            $table->string('business_name', 200)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->enum('tax_condition', ['registered_taxpayer', 'monotax', 'exempt', 'final_consumer', 'final_consumer_alt'])->default('final_consumer');
            $table->boolean('is_company_connection')->default(false);
            $table->uuid('connected_company_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('connected_company_id')->references('id')->on('companies')->onDelete('set null');
            $table->index('company_id');
            $table->index(['document_type', 'document_number']);
        });

        // Invoices
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 50);
            $table->enum('type', ['A', 'B', 'C', 'E', 'otro']);
            $table->unsignedSmallInteger('sales_point')->default(1);
            $table->unsignedInteger('voucher_number')->default(0);
            $table->string('afip_voucher_type', 3)->nullable();
            $table->enum('concept', ['products', 'services', 'products_and_services'])->default('products');
            $table->uuid('issuer_company_id');
            $table->uuid('receiver_company_id')->nullable();
            $table->uuid('client_id')->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('currency', 3)->default('ARS');
            $table->decimal('exchange_rate', 10, 4)->default(1.0000);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('total_taxes', 15, 2)->default(0);
            $table->decimal('total_perceptions', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->enum('status', ['pending_approval', 'issued', 'approved', 'rejected', 'in_dispute', 'correcting', 'paid', 'overdue', 'cancelled'])->default('pending_approval');
            $table->integer('approvals_required')->default(2);
            $table->integer('approvals_received')->default(0);
            $table->timestamp('approval_date')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->boolean('requires_correction')->default(false);
            $table->text('correction_notes')->nullable();
            $table->boolean('dispute_opened')->default(false);
            $table->text('dispute_reason')->nullable();
            $table->text('pdf_url')->nullable();
            $table->text('afip_txt_url')->nullable();
            $table->text('notes')->nullable();
            $table->text('collection_notes')->nullable();
            $table->date('declared_uncollectible_date')->nullable();
            $table->string('afip_cae', 50)->nullable();
            $table->date('afip_cae_due_date')->nullable();
            $table->enum('afip_status', ['pending', 'processing', 'approved', 'rejected', 'error'])->default('pending');
            $table->text('afip_error_message')->nullable();
            $table->timestamp('afip_sent_at')->nullable();
            $table->uuid('created_by');
            $table->softDeletes();
            $table->timestamps();
            
            $table->unique(['issuer_company_id', 'sales_point', 'voucher_number']);
            $table->foreign('issuer_company_id')->references('id')->on('companies');
            $table->foreign('receiver_company_id')->references('id')->on('companies');
            $table->foreign('client_id')->references('id')->on('clients');
            $table->foreign('rejected_by')->references('id')->on('users');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['issuer_company_id', 'status']);
            $table->index(['issuer_company_id', 'issue_date']);
            $table->index('due_date');
        });

        // Invoice Items
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->text('description');
            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->integer('order_index');
            
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->index('invoice_id');
        });

        // Invoice Taxes
        Schema::create('invoice_taxes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->string('name', 100);
            $table->decimal('rate', 5, 2);
            $table->decimal('base_amount', 15, 2);
            $table->decimal('amount', 15, 2);
            
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
        });

        // Invoice Perceptions
        Schema::create('invoice_perceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->enum('type', ['vat_perception', 'gross_income_perception', 'social_security_perception']);
            $table->string('name', 100);
            $table->decimal('rate', 5, 2);
            $table->decimal('base_amount', 15, 2);
            $table->decimal('amount', 15, 2);
            
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
        });

        // Invoice ARCA Files
        Schema::create('invoice_arca_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->enum('file_type', ['txt', 'pdf']);
            $table->string('file_path', 500);
            $table->integer('file_size')->nullable();
            $table->timestamp('downloaded_at')->useCurrent();
            $table->uuid('downloaded_by')->nullable();
            
            $table->unique(['invoice_id', 'file_type']);
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->foreign('downloaded_by')->references('id')->on('users');
            $table->index('invoice_id');
        });

        // Invoice Approvals
        Schema::create('invoice_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->uuid('approved_by');
            $table->timestamp('approved_at')->useCurrent();
            $table->text('notes')->nullable();
            
            $table->unique(['invoice_id', 'approved_by']);
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users');
        });

        // Entity Comments
        Schema::create('entity_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 50);
            $table->uuid('entity_id');
            $table->uuid('sender_id');
            $table->text('message');
            $table->boolean('is_internal')->default(false);
            $table->timestamp('created_at')->useCurrent();
            
            $table->foreign('sender_id')->references('id')->on('users');
            $table->index(['entity_type', 'entity_id']);
        });

        // Payments
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payer_company_id');
            $table->uuid('bank_account_id')->nullable();
            $table->date('payment_date');
            $table->enum('method', ['transfer', 'check', 'cash', 'card']);
            $table->decimal('original_amount', 15, 2);
            $table->decimal('total_retentions', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);
            $table->string('reference', 200)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['declared', 'confirmed', 'rejected', 'partial'])->default('declared');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->boolean('auto_resend_enabled')->default(true);
            $table->uuid('created_by');
            $table->softDeletes();
            $table->timestamps();
            
            $table->foreign('payer_company_id')->references('id')->on('companies');
            $table->foreign('bank_account_id')->references('id')->on('company_bank_accounts');
            $table->foreign('confirmed_by')->references('id')->on('users');
            $table->foreign('rejected_by')->references('id')->on('users');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['payer_company_id', 'payment_date']);
            $table->index('status');
        });

        // Payment Invoices
        Schema::create('payment_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payment_id');
            $table->uuid('invoice_id');
            $table->decimal('amount_applied', 15, 2);
            
            $table->unique(['payment_id', 'invoice_id']);
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('cascade');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->index('payment_id');
            $table->index('invoice_id');
        });

        // Payment Retentions
        Schema::create('payment_retentions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payment_id');
            $table->enum('type', ['vat_retention', 'income_tax_retention', 'gross_income_retention', 'social_security_retention']);
            $table->string('name', 100);
            $table->decimal('rate', 5, 2);
            $table->decimal('base_amount', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->string('certificate_number', 50)->nullable();
            
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('cascade');
        });

        // Notifications
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('company_id')->nullable();
            $table->string('type', 50);
            $table->string('title', 200);
            $table->text('message');
            $table->json('data')->nullable();
            $table->boolean('read')->default(false);
            $table->timestamp('created_at')->useCurrent();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index('user_id');
            $table->index('read');
        });

        // Activity Log
        Schema::create('activity_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('company_id')->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 50);
            $table->uuid('entity_id');
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->index('user_id');
            $table->index('company_id');
            $table->index('created_at');
        });

        // User Sessions
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('token_hash');
            $table->timestamp('expires_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('payment_retentions');
        Schema::dropIfExists('payment_invoices');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('entity_comments');
        Schema::dropIfExists('invoice_approvals');
        Schema::dropIfExists('invoice_arca_files');
        Schema::dropIfExists('invoice_perceptions');
        Schema::dropIfExists('invoice_taxes');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('company_connections');
    }
};
