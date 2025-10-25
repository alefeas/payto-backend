<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupExternalEntities extends Command
{
    protected $signature = 'cleanup:external-entities';
    protected $description = 'Elimina todos los clientes y proveedores externos de empresas específicas';

    public function handle()
    {
        $companyIds = [
            '0199ef90-c616-7348-87b2-78adddc56e25',
            '019a0cbe-f8b8-712c-a0d3-dd7f999d84c4'
        ];

        $this->info('Iniciando limpieza de clientes y proveedores externos...');

        foreach ($companyIds as $companyId) {
            $this->info("Procesando empresa: {$companyId}");
            
            // Contar registros antes
            $clientsCount = Client::where('company_id', $companyId)->count();
            $suppliersCount = Supplier::where('company_id', $companyId)->count();
            
            $this->info("  - Clientes encontrados: {$clientsCount}");
            $this->info("  - Proveedores encontrados: {$suppliersCount}");

            if ($clientsCount > 0 || $suppliersCount > 0) {
                if ($this->confirm("¿Eliminar permanentemente todos los registros de esta empresa?")) {
                    
                    DB::transaction(function() use ($companyId) {
                        // Eliminar clientes (incluyendo archivados)
                        $deletedClients = Client::where('company_id', $companyId)
                            ->withTrashed()
                            ->forceDelete();
                        
                        // Eliminar proveedores (incluyendo archivados)  
                        $deletedSuppliers = Supplier::where('company_id', $companyId)
                            ->withTrashed()
                            ->forceDelete();
                            
                        $this->info("  ✅ Eliminados permanentemente todos los registros");
                    });
                } else {
                    $this->info("  ⏭️  Omitiendo empresa");
                }
            } else {
                $this->info("  ℹ️  No hay registros para eliminar");
            }
            
            $this->info('');
        }

        $this->info('🎉 Limpieza completada!');
        return 0;
    }
}