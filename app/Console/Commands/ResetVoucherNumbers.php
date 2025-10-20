<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class ResetVoucherNumbers extends Command
{
    protected $signature = 'vouchers:reset {company_id} {sales_point}';
    protected $description = 'Reinicia los números de comprobante de un punto de venta (ELIMINA TODAS LAS FACTURAS)';

    public function handle()
    {
        $companyId = $this->argument('company_id');
        $salesPoint = (int) $this->argument('sales_point');

        $company = Company::find($companyId);
        
        if (!$company) {
            $this->error('Empresa no encontrada');
            return 1;
        }

        $this->warn('⚠️  ADVERTENCIA ⚠️');
        $this->warn('Esta operación ELIMINARÁ TODAS las facturas del punto de venta ' . $salesPoint);
        $this->warn('Empresa: ' . $company->name);
        $this->warn('CUIT: ' . $company->national_id);
        $this->newLine();
        
        if (!$this->confirm('¿Estás seguro de que quieres continuar?', false)) {
            $this->info('Operación cancelada');
            return 0;
        }

        $this->newLine();
        if (!$this->confirm('¿REALMENTE seguro? Esta acción NO se puede deshacer', false)) {
            $this->info('Operación cancelada');
            return 0;
        }

        try {
            DB::beginTransaction();

            $deletedCount = Invoice::where('issuer_company_id', $companyId)
                ->where('sales_point', $salesPoint)
                ->delete();

            DB::commit();

            $this->newLine();
            $this->info("✓ Operación completada exitosamente");
            $this->info("✓ Facturas eliminadas: {$deletedCount}");
            $this->info("✓ Próximo número de comprobante: 1");
            $this->newLine();
            $this->warn("Recuerda: AFIP determinará el próximo número real al crear la siguiente factura");

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error al reiniciar números: ' . $e->getMessage());
            return 1;
        }
    }
}
