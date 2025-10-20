<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetVoucherNumbers extends Command
{
    protected $signature = 'vouchers:reset {company_id} {sales_point=1}';
    protected $description = 'Reinicia los números de comprobante de una empresa y punto de venta';

    public function handle()
    {
        $companyId = $this->argument('company_id');
        $salesPoint = (int) $this->argument('sales_point');
        
        $company = Company::find($companyId);
        if (!$company) {
            $this->error('Empresa no encontrada');
            return 1;
        }
        
        $this->info("Empresa: {$company->name}");
        $this->info("Punto de venta: {$salesPoint}");
        
        // Contar facturas existentes
        $count = Invoice::where('issuer_company_id', $companyId)
            ->where('sales_point', $salesPoint)
            ->count();
        
        if ($count > 0) {
            $this->warn("⚠️  Hay {$count} facturas existentes para este punto de venta.");
            if (!$this->confirm('¿Estás seguro de eliminarlas? Esta acción NO se puede deshacer.')) {
                $this->info('Operación cancelada');
                return 0;
            }
        }
        
        DB::beginTransaction();
        try {
            // Eliminar facturas del punto de venta
            $deleted = Invoice::where('issuer_company_id', $companyId)
                ->where('sales_point', $salesPoint)
                ->delete();
            
            DB::commit();
            
            $this->info("✅ Se eliminaron {$deleted} facturas.");
            $this->info('✅ El próximo número de comprobante será: 1');
            $this->warn('⚠️  Recuerda que en AFIP debes usar el número que ellos te indiquen.');
            
            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }
}
