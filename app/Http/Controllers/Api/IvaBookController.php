<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\CuitValidatorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IvaBookController extends Controller
{
    use ApiResponse;

    /**
     * Normaliza condición IVA según AFIP para Libro IVA
     * Consumidor Final y Monotributo = Responsable No Inscripto
     */
    private function normalizeAfipTaxCondition(?string $taxCondition): string
    {
        if (!$taxCondition) return 'Responsable No Inscripto';
        
        return match($taxCondition) {
            'registered_taxpayer' => 'Responsable Inscripto',
            'exempt' => 'Exento',
            'final_consumer', 'monotax' => 'Responsable No Inscripto',
            default => 'Responsable No Inscripto'
        };
    }

    public function getSalesBook(Request $request, string $companyId): JsonResponse
    {
        $company = Company::with('members')->whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())->where('is_active', true);
        })->findOrFail($companyId);

        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $month = $request->input('month');
        $year = $request->input('year');

        // Obtener facturas emitidas del período (incluye NC/ND asociadas)
        // Incluir TODAS excepto archived (rechazadas sin CAE)
        // IMPORTANTE: Solo facturas donde la empresa es EMISORA, no receptora
        $invoices = Invoice::where('issuer_company_id', $companyId)
            ->where(function($query) use ($companyId) {
                // Excluir facturas donde la empresa es también receptora (facturas internas problemáticas)
                $query->where('receiver_company_id', '!=', $companyId)
                      ->orWhereNull('receiver_company_id');
            })
            ->whereYear('issue_date', $year)
            ->whereMonth('issue_date', $month)
            ->where('status', '!=', 'archived') // Excluir solo rechazadas sin CAE
            ->with([
                'client' => function($query) {
                    $query->withTrashed(); // Incluir clientes eliminados
                },
                'receiverCompany', // Empresa receptora (puede ser conexión eliminada)
                'items',
                'perceptions',
                'relatedInvoice.client' => function($query) {
                    $query->withTrashed(); // Incluir clientes eliminados en NC/ND
                }
            ])
            ->orderBy('issue_date')
            ->orderBy('voucher_number')
            ->get();

        $records = [];
        $totals = [
            'neto_gravado' => 0,
            'iva_21' => 0,
            'iva_105' => 0,
            'iva_27' => 0,
            'iva_25' => 0,
            'iva_5' => 0,
            'exento' => 0,
            'no_gravado' => 0,
            'percepciones' => 0,
            'total' => 0,
        ];

        foreach ($invoices as $invoice) {
            // Obtener cliente (directo, de factura relacionada, o empresa receptora)
            $client = $invoice->client ?? $invoice->relatedInvoice?->client;
            $receiverCompany = $invoice->receiverCompany;
            
            if ($client) {
                $clientName = $client->business_name ?? trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')) ?: 'Sin nombre';
                $clientCuit = $client->document_number;
                $clientTaxCondition = $this->normalizeAfipTaxCondition($client->tax_condition);
            } elseif ($receiverCompany) {
                $clientName = $receiverCompany->name ?: $receiverCompany->business_name ?: 'Empresa sin nombre';
                $clientCuit = $receiverCompany->national_id ?: $receiverCompany->cuit ?: '00000000000';
                $clientTaxCondition = $this->normalizeAfipTaxCondition($receiverCompany->tax_condition);
            } else {
                $clientName = 'CLIENTE ELIMINADO';
                $clientCuit = '00000000000';
                $clientTaxCondition = 'Responsable No Inscripto';
            }
            
            // NC resta, ND/Factura suma
            $isCredit = str_starts_with($invoice->type, 'NC');
            $multiplier = $isCredit ? -1 : 1;
            
            $record = [
                'fecha' => $invoice->issue_date->format('d/m/Y'),
                'tipo' => $invoice->type,
                'punto_venta' => str_pad($invoice->sales_point, 4, '0', STR_PAD_LEFT),
                'numero' => str_pad($invoice->voucher_number, 8, '0', STR_PAD_LEFT),
                'cliente' => $clientName,
                'cuit' => $clientCuit,
                'condicion_iva' => $clientTaxCondition,
                'neto_gravado' => 0,
                'iva_21' => 0,
                'iva_105' => 0,
                'iva_27' => 0,
                'iva_25' => 0,
                'iva_5' => 0,
                'exento' => 0,
                'no_gravado' => 0,
                'percepciones' => (($invoice->total_perceptions ?? 0) * ($invoice->exchange_rate ?? 1)) * $multiplier,
                'total' => ($invoice->total * ($invoice->exchange_rate ?? 1)) * $multiplier,
            ];

            // Agregar CAE si existe
            if ($invoice->cae) {
                $record['cae'] = $invoice->cae;
                $record['cae_expiration'] = $invoice->cae_expiration;
            }

            // Agrupar por alícuota (convertir a ARS)
            foreach ($invoice->items as $item) {
                $itemSubtotal = ($item->subtotal * ($invoice->exchange_rate ?? 1)) * $multiplier;
                $itemTax = ($item->tax_amount * ($invoice->exchange_rate ?? 1)) * $multiplier;

                if ($item->tax_rate == 21) {
                    $record['neto_gravado'] += $itemSubtotal;
                    $record['iva_21'] += $itemTax;
                } elseif ($item->tax_rate == 10.5) {
                    $record['neto_gravado'] += $itemSubtotal;
                    $record['iva_105'] += $itemTax;
                } elseif ($item->tax_rate == 27) {
                    $record['neto_gravado'] += $itemSubtotal;
                    $record['iva_27'] += $itemTax;
                } elseif ($item->tax_rate == 2.5) {
                    $record['neto_gravado'] += $itemSubtotal;
                    $record['iva_25'] += $itemTax;
                } elseif ($item->tax_rate == 5) {
                    $record['neto_gravado'] += $itemSubtotal;
                    $record['iva_5'] += $itemTax;
                } elseif ($item->tax_rate == -1) {
                    $record['exento'] += $itemSubtotal;
                } elseif ($item->tax_rate == -2) {
                    $record['no_gravado'] += $itemSubtotal;
                } elseif ($item->tax_rate == 0) {
                    $record['no_gravado'] += $itemSubtotal;
                }
            }

            // Acumular totales
            $totals['neto_gravado'] += $record['neto_gravado'];
            $totals['iva_21'] += $record['iva_21'];
            $totals['iva_105'] += $record['iva_105'];
            $totals['iva_27'] += $record['iva_27'];
            $totals['iva_25'] += $record['iva_25'];
            $totals['iva_5'] += $record['iva_5'];
            $totals['exento'] += $record['exento'];
            $totals['no_gravado'] += $record['no_gravado'];
            $totals['percepciones'] += $record['percepciones'];
            $totals['total'] += $record['total'];

            $records[] = $record;
        }

        // Detectar montos sospechosamente altos
        $warnings = [];
        foreach ($records as $record) {
            if ($record['total'] > 10000000) { // > $10M
                $warnings[] = "Factura {$record['tipo']} {$record['punto_venta']}-{$record['numero']} tiene un monto muy alto: " . number_format($record['total'], 2);
            }
        }
        
        $debitoFiscal = $totals['iva_21'] + $totals['iva_105'] + $totals['iva_27'] + $totals['iva_25'] + $totals['iva_5'];
        
        return $this->success([
            'period' => [
                'month' => $month,
                'year' => $year,
                'month_name' => \Carbon\Carbon::create($year, $month)->locale('es')->monthName,
            ],
            'records' => $records,
            'totals' => $totals,
            'debito_fiscal' => $debitoFiscal,
            'warnings' => $warnings,
        ]);
    }

    public function getPurchasesBook(Request $request, string $companyId): JsonResponse
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())->where('is_active', true);
        })->findOrFail($companyId);

        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $month = $request->input('month');
        $year = $request->input('year');

        // Obtener facturas recibidas del período
        // Incluir TODAS excepto archived (rechazadas sin CAE)
        // IMPORTANTE: Incluir facturas con supplier_id (proveedores externos) O receiver_company_id (empresas conectadas)
        $invoices = Invoice::where(function($query) use ($companyId) {
                // Facturas recibidas de empresas conectadas
                $query->where('receiver_company_id', $companyId)
                      ->where('issuer_company_id', '!=', $companyId);
            })
            ->orWhere(function($query) use ($companyId) {
                // Facturas de proveedores externos (supplier_id)
                $query->whereNotNull('supplier_id')
                      ->whereHas('supplier', function($q) use ($companyId) {
                          // Verificar que el proveedor pertenece a esta empresa
                          $q->where('company_id', $companyId);
                      });
            })
            ->whereYear('issue_date', $year)
            ->whereMonth('issue_date', $month)
            ->where('status', '!=', 'archived') // Excluir solo rechazadas sin CAE
            ->with([
                'supplier' => function($query) {
                    $query->withTrashed(); // Incluir proveedores eliminados
                },
                'issuerCompany', // Empresa emisora (puede ser conexión eliminada)
                'items',
                'perceptions'
            ])
            ->orderBy('issue_date')
            ->orderBy('voucher_number')
            ->get();

        $records = [];
        $totals = [
            'neto_gravado' => 0,
            'iva_21' => 0,
            'iva_105' => 0,
            'iva_27' => 0,
            'iva_25' => 0,
            'iva_5' => 0,
            'exento' => 0,
            'no_gravado' => 0,
            'percepciones' => 0,
            'retenciones' => 0,
            'total' => 0,
        ];

        foreach ($invoices as $invoice) {
            // Obtener retenciones de los pagos
            $retentions = Payment::where('invoice_id', $invoice->id)
                ->where('status', 'confirmed')
                ->get()
                ->flatMap(function ($payment) {
                    return $payment->retentions ?? [];
                })
                ->sum('amount');

            $supplier = $invoice->supplier;
            $issuerCompany = $invoice->issuerCompany;
            
            if ($supplier) {
                $supplierName = $supplier->business_name ?? trim(($supplier->first_name ?? '') . ' ' . ($supplier->last_name ?? '')) ?: 'Sin nombre';
                $supplierCuit = $supplier->document_number;
                $supplierTaxCondition = $this->normalizeAfipTaxCondition($supplier->tax_condition);
            } elseif ($issuerCompany) {
                $supplierName = $issuerCompany->name ?: $issuerCompany->business_name ?: 'Empresa sin nombre';
                $supplierCuit = $issuerCompany->national_id ?: $issuerCompany->cuit ?: '00000000000';
                $supplierTaxCondition = $this->normalizeAfipTaxCondition($issuerCompany->tax_condition);
            } else {
                $supplierName = 'PROVEEDOR ELIMINADO';
                $supplierCuit = '00000000000';
                $supplierTaxCondition = 'Responsable No Inscripto';
            }
            
            // En COMPRAS: NC suma (te devuelven IVA), ND resta (te cobran más IVA)
            $isCredit = str_starts_with($invoice->type, 'NC');
            $multiplier = $isCredit ? 1 : ($invoice->type[0] === 'N' && $invoice->type[1] === 'D' ? -1 : 1);
            
            $record = [
                'fecha' => $invoice->issue_date->format('d/m/Y'),
                'tipo' => $invoice->type,
                'punto_venta' => str_pad($invoice->sales_point, 4, '0', STR_PAD_LEFT),
                'numero' => str_pad($invoice->voucher_number, 8, '0', STR_PAD_LEFT),
                'proveedor' => $supplierName,
                'cuit' => $supplierCuit,
                'condicion_iva' => $supplierTaxCondition,
                'neto_gravado' => 0,
                'iva_21' => 0,
                'iva_105' => 0,
                'iva_27' => 0,
                'iva_25' => 0,
                'iva_5' => 0,
                'exento' => 0,
                'no_gravado' => 0,
                'percepciones' => (($invoice->total_perceptions ?? 0) * ($invoice->exchange_rate ?? 1)) * $multiplier,
                'retenciones' => $retentions * $multiplier,
                'total' => ($invoice->total * ($invoice->exchange_rate ?? 1)) * $multiplier,
            ];

            // Agrupar por alícuota (convertir a ARS)
            foreach ($invoice->items as $item) {
                $itemSubtotal = ($item->subtotal * ($invoice->exchange_rate ?? 1)) * $multiplier;
                $itemTax = ($item->tax_amount * ($invoice->exchange_rate ?? 1)) * $multiplier;

                if ($item->tax_rate == 21) {
                    $record['neto_gravado'] += $itemSubtotal;
                    $record['iva_21'] += $itemTax;
                } elseif ($item->tax_rate == 10.5) {
                    $record['neto_gravado'] += $itemSubtotal;
                    $record['iva_105'] += $itemTax;
                } elseif ($item->tax_rate == 27) {
                    $record['neto_gravado'] += $itemSubtotal;
                    $record['iva_27'] += $itemTax;
                } elseif ($item->tax_rate == 2.5) {
                    $record['neto_gravado'] += $itemSubtotal;
                    $record['iva_25'] += $itemTax;
                } elseif ($item->tax_rate == 5) {
                    $record['neto_gravado'] += $itemSubtotal;
                    $record['iva_5'] += $itemTax;
                } elseif ($item->tax_rate == -1) {
                    $record['exento'] += $itemSubtotal;
                } elseif ($item->tax_rate == -2) {
                    $record['no_gravado'] += $itemSubtotal;
                } elseif ($item->tax_rate == 0) {
                    $record['no_gravado'] += $itemSubtotal;
                }
            }

            // Acumular totales
            $totals['neto_gravado'] += $record['neto_gravado'];
            $totals['iva_21'] += $record['iva_21'];
            $totals['iva_105'] += $record['iva_105'];
            $totals['iva_27'] += $record['iva_27'];
            $totals['iva_25'] += $record['iva_25'];
            $totals['iva_5'] += $record['iva_5'];
            $totals['exento'] += $record['exento'];
            $totals['no_gravado'] += $record['no_gravado'];
            $totals['percepciones'] += $record['percepciones'];
            $totals['retenciones'] += $record['retenciones'];
            $totals['total'] += $record['total'];

            $records[] = $record;
        }

        // Detectar montos sospechosamente altos
        $warnings = [];
        foreach ($records as $record) {
            if ($record['total'] > 10000000) { // > $10M
                $warnings[] = "Factura {$record['tipo']} {$record['punto_venta']}-{$record['numero']} tiene un monto muy alto: " . number_format($record['total'], 2);
            }
        }
        
        $creditoFiscal = $totals['iva_21'] + $totals['iva_105'] + $totals['iva_27'] + $totals['iva_25'] + $totals['iva_5'];
        
        return $this->success([
            'period' => [
                'month' => $month,
                'year' => $year,
                'month_name' => \Carbon\Carbon::create($year, $month)->locale('es')->monthName,
            ],
            'records' => $records,
            'totals' => $totals,
            'credito_fiscal' => $creditoFiscal,
            'warnings' => $warnings,
        ]);
    }

    public function getSummary(Request $request, string $companyId): JsonResponse
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())->where('is_active', true);
        })->findOrFail($companyId);

        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        // Obtener ambos libros
        $salesRequest = new Request(['month' => $request->month, 'year' => $request->year]);
        $salesBook = $this->getSalesBook($salesRequest, $companyId)->getData();
        $purchasesBook = $this->getPurchasesBook($salesRequest, $companyId)->getData();

        $debitoFiscal = $salesBook->data->debito_fiscal ?? 0;
        $creditoFiscal = $purchasesBook->data->credito_fiscal ?? 0;
        $saldo = $debitoFiscal - $creditoFiscal;

        return $this->success([
            'period' => $salesBook->data->period,
            'debito_fiscal' => $debitoFiscal,
            'credito_fiscal' => $creditoFiscal,
            'saldo' => $saldo,
            'saldo_type' => $saldo >= 0 ? 'a_pagar' : 'a_favor',
        ]);
    }

    public function exportSalesAfip(Request $request, string $companyId)
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())->where('is_active', true);
        })->findOrFail($companyId);

        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $salesRequest = new Request(['month' => $request->month, 'year' => $request->year]);
        $salesBook = $this->getSalesBook($salesRequest, $companyId)->getData();
        
        // Validar solo CUITs (no DNIs de CF)
        $invalidCuits = [];
        foreach ($salesBook->data->records as $record) {
            $record = (array)$record;
            $cuit = $record['cuit'];
            // Solo validar si es CUIT (11 dígitos) y no es el por defecto
            if ($cuit !== '00000000000' && strlen($cuit) == 11 && !CuitValidatorService::isValid($cuit)) {
                $invalidCuits[] = $cuit . ' (' . $record['cliente'] . ')';
            }
        }
        
        if (count($invalidCuits) > 0) {
            $invalidList = implode(', ', array_slice($invalidCuits, 0, 5)) . 
                          (count($invalidCuits) > 5 ? ' y ' . (count($invalidCuits) - 5) . ' más' : '');
            
            return $this->error(
                'Se encontraron ' . count($invalidCuits) . ' CUIT(s) inválido(s): ' . $invalidList . '. Corrige los CUITs inválidos editando los clientes/empresas en sus secciones respectivas.',
                422
            );
        }

        $content = $this->generateAfipSalesTxt($salesBook->data, $company);
        $filename = "REGINFO_CV_VENTAS_{$request->year}_{$request->month}.txt";

        return response($content, 200, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }

    public function exportPurchasesAfip(Request $request, string $companyId)
    {
        $company = Company::whereHas('members', function ($query) {
            $query->where('user_id', auth()->id())->where('is_active', true);
        })->findOrFail($companyId);

        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $purchasesRequest = new Request(['month' => $request->month, 'year' => $request->year]);
        $purchasesBook = $this->getPurchasesBook($purchasesRequest, $companyId)->getData();
        
        // Validar solo CUITs (no DNIs de CF)
        $invalidCuits = [];
        foreach ($purchasesBook->data->records as $record) {
            $record = (array)$record;
            $cuit = $record['cuit'];
            // Solo validar si es CUIT (11 dígitos) y no es el por defecto
            if ($cuit !== '00000000000' && strlen($cuit) == 11 && !CuitValidatorService::isValid($cuit)) {
                $invalidCuits[] = $cuit . ' (' . $record['proveedor'] . ')';
            }
        }
        
        if (count($invalidCuits) > 0) {
            $invalidList = implode(', ', array_slice($invalidCuits, 0, 5)) . 
                          (count($invalidCuits) > 5 ? ' y ' . (count($invalidCuits) - 5) . ' más' : '');
            
            return $this->error(
                'Se encontraron ' . count($invalidCuits) . ' CUIT(s) inválido(s): ' . $invalidList . '. Corrige los CUITs inválidos editando los proveedores/empresas en sus secciones respectivas.',
                422
            );
        }

        $content = $this->generateAfipPurchasesTxt($purchasesBook->data, $company);
        $filename = "REGINFO_CV_COMPRAS_{$request->year}_{$request->month}.txt";

        return response($content, 200, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }

    private function generateAfipSalesTxt($data, $company): string
    {
        $lines = [];
        $records = is_array($data->records) ? $data->records : (array)$data->records;
        
        foreach ($records as $record) {
            $record = (array)$record;
            // Formato REGINFO AFIP - Ventas (posición fija)
            $fecha = \Carbon\Carbon::createFromFormat('d/m/Y', $record['fecha'])->format('Ymd');
            $cuit = str_pad(str_replace('-', '', $record['cuit']), 11, '0', STR_PAD_LEFT);
            $clientName = $this->normalizeAfipText($record['cliente']);
            
            // CAE y fecha vencimiento (si existen)
            $cae = isset($record['cae']) ? str_pad($record['cae'], 14, '0', STR_PAD_LEFT) : str_repeat('0', 14);
            $caeVto = isset($record['cae_expiration']) ? \Carbon\Carbon::parse($record['cae_expiration'])->format('Ymd') : str_repeat('0', 8);
            
            // Contar alícuotas IVA activas
            $cantAlicuotas = 0;
            if ($record['iva_21'] != 0) $cantAlicuotas++;
            if ($record['iva_105'] != 0) $cantAlicuotas++;
            if ($record['iva_27'] != 0) $cantAlicuotas++;
            if ($record['iva_5'] != 0) $cantAlicuotas++;
            if ($record['iva_25'] != 0) $cantAlicuotas++;
            
            $line = implode('', [
                $fecha,                                                     // 1-8: Fecha (AAAAMMDD)
                str_pad($this->getAfipInvoiceType($record['tipo']), 3, '0', STR_PAD_LEFT), // 9-11: Tipo comprobante
                str_pad($record['punto_venta'], 5, '0', STR_PAD_LEFT),    // 12-16: Punto venta
                str_pad($record['numero'], 20, '0', STR_PAD_LEFT),        // 17-36: Número desde
                str_pad($record['numero'], 20, '0', STR_PAD_LEFT),        // 37-56: Número hasta
                str_pad('80', 2, '0', STR_PAD_LEFT),                      // 57-58: Código doc comprador (80=CUIT)
                $cuit,                                                     // 59-69: Nro doc comprador
                str_pad(substr($clientName, 0, 30), 30, ' '),             // 70-99: Apellido y nombre
                $this->formatAmount($record['total']),                    // 100-114: Importe total
                $this->formatAmount($record['neto_gravado']),             // 115-129: Importe neto gravado
                $this->formatAmount($record['no_gravado'] ?? 0),          // 130-144: Importe neto no gravado
                $this->formatAmount($record['exento'] ?? 0),              // 145-159: Importe exento
                $this->formatAmount($record['percepciones']),             // 160-174: Percepciones IVA
                $this->formatAmount($record['iva_21']),                   // 175-189: IVA 21%
                $this->formatAmount($record['iva_105']),                  // 190-204: IVA 10.5%
                $this->formatAmount($record['iva_27']),                   // 205-219: IVA 27%
                $this->formatAmount($record['iva_5']),                    // 220-234: IVA 5%
                $this->formatAmount($record['iva_25']),                   // 235-249: IVA 2.5%
                str_pad('PES', 3, ' '),                                   // 250-252: Código moneda
                str_pad('0001000000', 10, '0', STR_PAD_LEFT),            // 253-262: Tipo cambio
                str_pad($cantAlicuotas, 1, '0', STR_PAD_LEFT),           // 263: Cantidad alícuotas IVA
                ' ',                                                      // 264: Código operación
                str_repeat('0', 15),                                      // 265-279: Otros tributos
                $caeVto,                                                  // 280-287: Fecha vencimiento pago
                $cae,                                                     // 288-301: CAE
            ]);
            $lines[] = $line;
        }

        return implode("\r\n", $lines);
    }

    private function generateAfipPurchasesTxt($data, $company): string
    {
        $lines = [];
        $records = is_array($data->records) ? $data->records : (array)$data->records;
        
        foreach ($records as $record) {
            $record = (array)$record;
            // Formato REGINFO AFIP - Compras (posición fija)
            $fecha = \Carbon\Carbon::createFromFormat('d/m/Y', $record['fecha'])->format('Ymd');
            $cuit = str_pad(str_replace('-', '', $record['cuit']), 11, '0', STR_PAD_LEFT);
            $supplierName = $this->normalizeAfipText($record['proveedor']);
            
            // Contar alícuotas IVA activas
            $cantAlicuotas = 0;
            if ($record['iva_21'] != 0) $cantAlicuotas++;
            if ($record['iva_105'] != 0) $cantAlicuotas++;
            if ($record['iva_27'] != 0) $cantAlicuotas++;
            if ($record['iva_5'] != 0) $cantAlicuotas++;
            if ($record['iva_25'] != 0) $cantAlicuotas++;
            
            $line = implode('', [
                $fecha,                                                     // 1-8: Fecha
                str_pad($this->getAfipInvoiceType($record['tipo']), 3, '0', STR_PAD_LEFT), // 9-11: Tipo comprobante
                str_pad($record['punto_venta'], 5, '0', STR_PAD_LEFT),    // 12-16: Punto venta
                str_pad($record['numero'], 20, '0', STR_PAD_LEFT),        // 17-36: Número desde
                str_pad($record['numero'], 20, '0', STR_PAD_LEFT),        // 37-56: Número hasta
                str_pad('80', 2, '0', STR_PAD_LEFT),                      // 57-58: Código doc vendedor (80=CUIT)
                $cuit,                                                     // 59-69: Nro doc vendedor
                str_pad(substr($supplierName, 0, 30), 30, ' '),           // 70-99: Apellido y nombre
                $this->formatAmount($record['total']),                    // 100-114: Importe total
                $this->formatAmount($record['neto_gravado']),             // 115-129: Importe neto gravado
                $this->formatAmount($record['no_gravado'] ?? 0),          // 130-144: Importe neto no gravado
                $this->formatAmount($record['exento'] ?? 0),              // 145-159: Importe exento
                $this->formatAmount($record['percepciones']),             // 160-174: Percepciones IVA
                $this->formatAmount($record['iva_21']),                   // 175-189: IVA 21%
                $this->formatAmount($record['iva_105']),                  // 190-204: IVA 10.5%
                $this->formatAmount($record['iva_27']),                   // 205-219: IVA 27%
                $this->formatAmount($record['iva_5']),                    // 220-234: IVA 5%
                $this->formatAmount($record['iva_25']),                   // 235-249: IVA 2.5%
                str_pad('PES', 3, ' '),                                   // 250-252: Código moneda
                str_pad('0001000000', 10, '0', STR_PAD_LEFT),            // 253-262: Tipo cambio
                str_pad($cantAlicuotas, 1, '0', STR_PAD_LEFT),           // 263: Cantidad alícuotas IVA
                ' ',                                                      // 264: Código operación
                $this->formatAmount($record['retenciones']),              // 265-279: Crédito fiscal computable
                str_repeat('0', 15),                                      // 280-294: Otros tributos
                str_repeat('0', 11),                                      // 295-305: CUIT emisor
                str_repeat(' ', 30),                                      // 306-335: Denominación emisor
                $this->formatAmount($record['iva_21']),                   // 336-350: IVA comisión
            ]);
            $lines[] = $line;
        }

        return implode("\r\n", $lines);
    }

    private function formatAmount($amount): string
    {
        // Formato AFIP: 15 dígitos, sin separadores, últimos 2 son decimales
        // Ejemplo: 1234.56 -> 000000000123456
        $cents = round($amount * 100);
        return str_pad($cents, 15, '0', STR_PAD_LEFT);
    }

    private function getAfipInvoiceType(string $type): int
    {
        // Códigos oficiales AFIP para REGINFO
        $types = [
            'FC A' => 1, 'FC B' => 6, 'FC C' => 11,
            'NC A' => 3, 'NC B' => 8, 'NC C' => 13,
            'ND A' => 2, 'ND B' => 7, 'ND C' => 12,
            'Recibo A' => 4, 'Recibo B' => 9, 'Recibo C' => 15,
            'FC E' => 19, 'NC E' => 21, 'ND E' => 20,
            'FC M' => 51, 'NC M' => 53, 'ND M' => 52,
        ];
        return $types[$type] ?? 1;
    }

    private function normalizeAfipText(string $text): string
    {
        // Convertir a mayúsculas
        $text = mb_strtoupper($text, 'UTF-8');
        
        // Reemplazar caracteres especiales por equivalentes ASCII
        $replacements = [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O', 'Ü' => 'U',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
            'Â' => 'A', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U',
            'Ñ' => 'N',
            'Ç' => 'C',
            '°' => ' ',
            'º' => ' ',
            'ª' => ' ',
        ];
        
        $text = strtr($text, $replacements);
        
        // Eliminar cualquier carácter que no sea alfanumérico, espacio, punto, coma o guión
        $text = preg_replace('/[^A-Z0-9 .,-]/', '', $text);
        
        // Reemplazar múltiples espacios por uno solo
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }
}
