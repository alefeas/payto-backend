<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Company;
use App\Services\AfipService;

class CheckAfipLastVoucher extends Command
{
    protected $signature = 'afip:check-last {company_id} {sales_point} {voucher_type=1}';
    protected $description = 'Consulta el último número de comprobante autorizado en AFIP';

    public function handle()
    {
        $companyId = $this->argument('company_id');
        $salesPoint = (int) $this->argument('sales_point');
        $voucherType = (int) $this->argument('voucher_type');

        $company = Company::find($companyId);
        
        if (!$company) {
            $this->error('Empresa no encontrada');
            return 1;
        }

        $this->info("Consultando AFIP para:");
        $this->info("- Empresa: {$company->name}");
        $this->info("- CUIT: {$company->national_id}");
        $this->info("- Punto de Venta: {$salesPoint}");
        $this->info("- Tipo de Comprobante: {$voucherType} (1=Factura A, 6=Factura B, 11=Factura C)");
        $this->newLine();

        try {
            $afipService = new AfipService();
            $lastVoucher = $afipService->getLastAuthorizedInvoice($company, $salesPoint, $voucherType);
            
            $this->info("✓ Último número autorizado en AFIP: {$lastVoucher}");
            $this->info("→ El próximo número debe ser: " . ($lastVoucher + 1));
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Error al consultar AFIP: " . $e->getMessage());
            return 1;
        }
    }
}
