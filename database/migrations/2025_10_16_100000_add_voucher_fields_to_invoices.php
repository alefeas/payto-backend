<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Comprobante asociado (para NC/ND)
            $table->uuid('related_invoice_id')->nullable()->after('supplier_id');
            $table->foreign('related_invoice_id')->references('id')->on('invoices')->onDelete('set null');
            
            // Saldo pendiente (para control de NC/ND)
            $table->decimal('balance_pending', 15, 2)->nullable()->after('total');
            
            // Fecha de vencimiento de pago (para FCE MiPyME)
            $table->date('payment_due_date')->nullable()->after('due_date');
            
            // CBU del emisor (para FCE MiPyME)
            $table->string('issuer_cbu', 22)->nullable()->after('payment_due_date');
            
            // Estado de aceptación (para FCE MiPyME)
            $table->enum('acceptance_status', ['pending', 'accepted', 'rejected'])->nullable()->after('issuer_cbu');
            $table->timestamp('acceptance_date')->nullable()->after('acceptance_status');
            
            // Datos de transporte (para Remitos)
            $table->json('transport_data')->nullable()->after('acceptance_date');
            
            // Tipo de operación (para distinguir comprobantes especiales)
            $table->string('operation_type', 50)->nullable()->after('transport_data');
            
            // Actualizar enum de status para incluir nuevos estados
            DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM(
                'pending_approval', 'issued', 'approved', 'rejected', 'in_dispute', 
                'correcting', 'paid', 'overdue', 'cancelled', 'archived',
                'partially_cancelled', 'pending_acceptance'
            ) DEFAULT 'pending_approval'");
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['related_invoice_id']);
            $table->dropColumn([
                'related_invoice_id',
                'balance_pending',
                'payment_due_date',
                'issuer_cbu',
                'acceptance_status',
                'acceptance_date',
                'transport_data',
                'operation_type'
            ]);
        });
    }
};
