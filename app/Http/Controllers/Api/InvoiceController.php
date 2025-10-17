<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Afip\AfipInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class InvoiceController extends Controller
{
    use AuthorizesRequests;
    public function index(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        
        $this->authorize('viewAny', [Invoice::class, $company]);

        $status = $request->query('status');
        
        // Obtener TODAS las facturas relacionadas con la empresa (emitidas Y recibidas)
        $query = Invoice::where(function($q) use ($companyId) {
                $q->where('issuer_company_id', $companyId)
                  ->orWhere('receiver_company_id', $companyId);
            })
            ->with(['client', 'supplier', 'items', 'issuerCompany', 'receiverCompany', 'approvals.user']);

        if ($status) {
            $query->where('status', $status);
        }

        $invoices = $query->orderBy('created_at', 'desc')
            ->paginate(20);
        
        // Override approvals_required with current company setting and format approvals
        $invoices->getCollection()->transform(function ($invoice) use ($company, $companyId) {
            // Solo override approvals_required si la empresa es la receptora
            if ($invoice->receiver_company_id === $companyId) {
                $invoice->approvals_required = $company->required_approvals;
            }
            
            // Agregar campo de dirección para facilitar filtrado en frontend
            $invoice->direction = $invoice->issuer_company_id === $companyId ? 'issued' : 'received';
            
            // Format approvals for frontend
            if ($invoice->approvals && $invoice->approvals->count() > 0) {
                $invoice->approvals = $invoice->approvals->map(function ($approval) {
                    $fullName = trim($approval->user->first_name . ' ' . $approval->user->last_name);
                    return [
                        'id' => $approval->id,
                        'user' => [
                            'id' => $approval->user->id,
                            'name' => $fullName ?: $approval->user->email,
                            'first_name' => $approval->user->first_name,
                            'last_name' => $approval->user->last_name,
                            'email' => $approval->user->email,
                        ],
                        'notes' => $approval->notes,
                        'approved_at' => $approval->approved_at->toIso8601String(),
                    ];
                })->values();
            } else {
                $invoice->approvals = [];
            }
            
            return $invoice;
        });

        return response()->json($invoices);
    }

    public function store(Request $request, $companyId)
    {
        $company = Company::with('afipCertificate')->findOrFail($companyId);
        
        $this->authorize('create', [Invoice::class, $company]);

        $hasAfipCertificate = $company->afipCertificate && $company->afipCertificate->is_active;
        
        if (!$hasAfipCertificate) {
            return response()->json([
                'message' => 'Certificado AFIP requerido',
                'error' => 'Debes configurar tu certificado AFIP para emitir facturas electrónicas. Ve a Configuración → Verificar Perfil Fiscal.',
            ], 403);
        }

        $validated = $request->validate([
            'client_id' => 'required_without_all:client_data,receiver_company_id|exists:clients,id',
            'receiver_company_id' => 'nullable|exists:companies,id',
            'client_data' => 'required_without_all:client_id,receiver_company_id|array',
            'client_data.document_type' => 'required_with:client_data|in:CUIT,CUIL,DNI,Pasaporte,CDI',
            'client_data.document_number' => 'required_with:client_data|string',
            'client_data.business_name' => 'nullable|string',
            'client_data.first_name' => 'nullable|string',
            'client_data.last_name' => 'nullable|string',
            'client_data.email' => 'nullable|email',
            'client_data.tax_condition' => 'required_with:client_data|in:registered_taxpayer,monotax,exempt,final_consumer',
            'save_client' => 'boolean',
            'invoice_type' => 'required|string',
            'sales_point' => 'required|integer|min:1|max:9999',
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:issue_date',
            'currency' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'required|numeric|min:0|max:100',
            'perceptions' => 'nullable|array',
            'perceptions.*.type' => 'required|in:vat_perception,gross_income_perception,social_security_perception',
            'perceptions.*.name' => 'required|string',
            'perceptions.*.rate' => 'required|numeric|min:0|max:100',
        ]);

        try {
            DB::beginTransaction();

            // Create client if client_data is provided
            if (!isset($validated['client_id']) && isset($validated['client_data'])) {
                $client = \App\Models\Client::create([
                    'company_id' => $companyId,
                    ...$validated['client_data']
                ]);
                $validated['client_id'] = $client->id;
            }

            // Consultar último número autorizado en AFIP
            $afipService = new AfipInvoiceService($company);
            $invoiceTypeCode = $this->getAfipInvoiceTypeCode($validated['invoice_type']);
            
            try {
                $lastAfipNumber = $afipService->getLastAuthorizedInvoice(
                    $validated['sales_point'],
                    $invoiceTypeCode
                );
            } catch (\Exception $e) {
                Log::warning('Could not get last AFIP number, using local DB', [
                    'error' => $e->getMessage()
                ]);
                $lastAfipNumber = 0;
            }

            // Get last invoice number from database
            $lastInvoice = Invoice::where('issuer_company_id', $companyId)
                ->where('type', $validated['invoice_type'])
                ->where('sales_point', $validated['sales_point'])
                ->orderBy('voucher_number', 'desc')
                ->first();

            // Use the highest value between AFIP, DB and company's last_invoice_number
            $lastFromDb = $lastInvoice ? $lastInvoice->voucher_number : 0;
            $lastFromCompany = $company->last_invoice_number ?? 0;
            $voucherNumber = max($lastAfipNumber, $lastFromDb, $lastFromCompany) + 1;

            $subtotal = 0;
            $totalTaxes = 0;

            foreach ($validated['items'] as $item) {
                $itemSubtotal = $item['quantity'] * $item['unit_price'];
                $itemTax = $itemSubtotal * ($item['tax_rate'] / 100);
                
                $subtotal += $itemSubtotal;
                $totalTaxes += $itemTax;
            }

            // Calculate perceptions
            $totalPerceptions = 0;
            if (isset($validated['perceptions'])) {
                foreach ($validated['perceptions'] as $perception) {
                    $baseAmount = $perception['type'] === 'vat_perception' 
                        ? $totalTaxes 
                        : ($subtotal + $totalTaxes);
                    $totalPerceptions += $baseAmount * ($perception['rate'] / 100);
                }
            }

            $total = $subtotal + $totalTaxes + $totalPerceptions;

            $invoice = Invoice::create([
                'number' => sprintf('%04d-%08d', $validated['sales_point'], $voucherNumber),
                'type' => $validated['invoice_type'],
                'sales_point' => $validated['sales_point'],
                'voucher_number' => $voucherNumber,
                'concept' => 'products',
                'issuer_company_id' => $companyId,
                'receiver_company_id' => $validated['receiver_company_id'] ?? null,
                'client_id' => $validated['client_id'] ?? null,
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?? now()->addDays(30),
                'subtotal' => $subtotal,
                'total_taxes' => $totalTaxes,
                'total_perceptions' => $totalPerceptions,
                'total' => $total,
                'currency' => $validated['currency'] ?? 'ARS',
                'exchange_rate' => $validated['exchange_rate'] ?? 1,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending_approval',
                'afip_status' => 'pending',
                'approvals_required' => 0, // Set based on company settings
                'approvals_received' => 0,
                'created_by' => auth()->id(),
            ]);

            foreach ($validated['items'] as $index => $item) {
                $itemSubtotal = $item['quantity'] * $item['unit_price'];
                $itemTax = $itemSubtotal * ($item['tax_rate'] / 100);

                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'],
                    'tax_amount' => $itemTax,
                    'subtotal' => $itemSubtotal,
                    'order_index' => $index,
                ]);
            }

            // Create perceptions
            if (isset($validated['perceptions'])) {
                foreach ($validated['perceptions'] as $perception) {
                    $baseAmount = $perception['type'] === 'vat_perception' 
                        ? $totalTaxes 
                        : ($subtotal + $totalTaxes);
                    $amount = $baseAmount * ($perception['rate'] / 100);

                    $invoice->perceptions()->create([
                        'type' => $perception['type'],
                        'name' => $perception['name'],
                        'rate' => $perception['rate'],
                        'base_amount' => $baseAmount,
                        'amount' => $amount,
                    ]);
                }
            }

            // Authorize with AFIP
            try {
                $invoice->load('perceptions');
                $afipService = new AfipInvoiceService($company);
                $afipResult = $afipService->authorizeInvoice($invoice);
                
                $invoice->update([
                    'afip_cae' => $afipResult['cae'],
                    'afip_cae_due_date' => $afipResult['cae_expiration'],
                    'afip_status' => 'approved',
                    'status' => 'issued',
                    'afip_sent_at' => now(),
                ]);
                
                // Generate PDF and TXT
                $pdfService = new \App\Services\InvoicePdfService();
                $pdfPath = $pdfService->generatePdf($invoice);
                $txtPath = $pdfService->generateTxt($invoice);
                
                $invoice->update([
                    'pdf_url' => $pdfPath,
                    'afip_txt_url' => $txtPath,
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                
                Log::error('AFIP authorization failed - Invoice not created', [
                    'company_id' => $companyId,
                    'voucher_number' => $voucherNumber,
                    'error' => $e->getMessage(),
                ]);
                
                return response()->json([
                    'message' => 'AFIP rechazó la factura',
                    'error' => $e->getMessage(),
                ], 422);
            }

            // Update company's last invoice number
            $company->update([
                'last_invoice_number' => $voucherNumber
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Factura autorizada por AFIP exitosamente',
                'invoice' => $invoice->load(['client', 'items', 'perceptions']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Invoice creation failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)
            ->with(['client', 'items', 'receiverCompany'])
            ->findOrFail($id);

        $this->authorize('view', $invoice);

        return response()->json($invoice);
    }

    public function destroy($companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)->findOrFail($id);

        $this->authorize('delete', $invoice);

        // Only allow deletion of drafts, pending approval, or rejected invoices
        if (in_array($invoice->status, ['issued', 'approved', 'paid'])) {
            return response()->json([
                'message' => 'Cannot delete issued invoices. Use credit notes to cancel them.',
                'suggestion' => 'Create a credit note (Nota de Crédito) to cancel this invoice.',
            ], 422);
        }

        if ($invoice->afip_cae && !str_starts_with($invoice->afip_cae, 'SIM-')) {
            return response()->json([
                'message' => 'Cannot delete invoice with real AFIP CAE. Use credit notes to cancel.',
            ], 422);
        }

        $invoice->delete();

        return response()->json([
            'message' => 'Invoice deleted successfully',
        ]);
    }

    public function cancel($companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)->findOrFail($id);

        $this->authorize('delete', $invoice);

        if ($invoice->status === 'cancelled') {
            return response()->json([
                'message' => 'Invoice is already cancelled',
            ], 422);
        }

        // Mark as cancelled instead of deleting
        $invoice->update([
            'status' => 'cancelled',
        ]);

        return response()->json([
            'message' => 'Invoice cancelled successfully',
            'note' => 'To legally cancel this invoice, you should issue a credit note.',
        ]);
    }

    public function archive($companyId, $id)
    {
        $invoice = Invoice::where('receiver_company_id', $companyId)->findOrFail($id);

        $this->authorize('update', $invoice);

        if ($invoice->status !== 'rejected') {
            return response()->json([
                'message' => 'Only rejected invoices can be archived',
            ], 422);
        }

        $invoice->update([
            'status' => 'archived',
        ]);

        return response()->json([
            'message' => 'Invoice archived successfully',
        ]);
    }

    public function validateWithAfip(Request $request, $companyId)
    {
        $company = Company::with('afipCertificate')->findOrFail($companyId);
        
        $this->authorize('create', [Invoice::class, $company]);

        $validated = $request->validate([
            'issuer_cuit' => 'required|string',
            'invoice_type' => 'required|in:A,B,C,E',
            'invoice_number' => 'required|string|regex:/^\d{4}-\d{8}$/',
        ]);

        try {
            // Parse invoice number
            [$salesPoint, $voucherNumber] = explode('-', $validated['invoice_number']);
            $salesPoint = (int) $salesPoint;
            $voucherNumber = (int) $voucherNumber;

            $afipService = new AfipInvoiceService($company);
            $invoiceType = $this->getAfipInvoiceTypeCode($validated['invoice_type']);
            
            $result = $afipService->consultInvoice(
                $validated['issuer_cuit'],
                $invoiceType,
                $salesPoint,
                $voucherNumber
            );

            return response()->json([
                'success' => true,
                'invoice' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo validar la factura con AFIP',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function getAfipInvoiceTypeCode(string $type): int
    {
        $types = ['A' => 1, 'B' => 6, 'C' => 11, 'E' => 19];
        return $types[$type] ?? 6;
    }

    public function storeReceived(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);

        $validated = $request->validate([
            'issuer_cuit' => 'required|string',
            'issuer_business_name' => 'required|string|max:150',
            'invoice_type' => 'required|in:A,B,C,E',
            'invoice_number' => 'required|string',
            'issue_date' => 'required|date',
            'due_date' => 'required|date',
            'currency' => 'required|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:200',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'required|numeric',
        ]);

        try {
            DB::beginTransaction();

            // Limpiar CUIT
            $cleanCuit = preg_replace('/[^0-9]/', '', $validated['issuer_cuit']);
            
            // Buscar o crear proveedor (no cliente)
            $supplier = \App\Models\Supplier::firstOrCreate(
                [
                    'company_id' => $companyId,
                    'document_number' => $cleanCuit,
                ],
                [
                    'document_type' => 'CUIT',
                    'business_name' => $validated['issuer_business_name'],
                    'tax_condition' => 'registered_taxpayer',
                ]
            );

            // Calcular totales
            $subtotal = 0;
            $totalTaxes = 0;
            foreach ($validated['items'] as $item) {
                $itemSubtotal = $item['quantity'] * $item['unit_price'];
                $itemTax = $itemSubtotal * ($item['tax_rate'] / 100);
                $subtotal += $itemSubtotal;
                $totalTaxes += $itemTax;
            }
            $total = $subtotal + $totalTaxes;

            // Parsear el número de factura del proveedor (formato: 0001-00000123)
            $invoiceParts = explode('-', $validated['invoice_number']);
            $salesPoint = isset($invoiceParts[0]) ? (int)$invoiceParts[0] : 0;
            $voucherNumber = isset($invoiceParts[1]) ? (int)$invoiceParts[1] : 0;

            // Determinar estado inicial basado en configuración de aprobaciones
            $requiresApproval = $company->required_approvals > 0;
            
            // Crear factura recibida
            // NOTA: issuer_company_id se usa como placeholder (mismo que receiver) para cumplir constraint
            // El supplier_id identifica al verdadero emisor externo
            $invoice = Invoice::create([
                'number' => $validated['invoice_number'],
                'type' => $validated['invoice_type'],
                'sales_point' => $salesPoint,
                'voucher_number' => $voucherNumber,
                'concept' => 'products',
                'issuer_company_id' => $companyId, // Placeholder para constraint
                'receiver_company_id' => $companyId, // Tu empresa recibe
                'supplier_id' => $supplier->id, // Emisor real (externo)
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'subtotal' => $subtotal,
                'total_taxes' => $totalTaxes,
                'total_perceptions' => 0,
                'total' => $total,
                'currency' => $validated['currency'],
                'exchange_rate' => $validated['exchange_rate'] ?? 1,
                'notes' => $validated['notes'] ?? null,
                'status' => $requiresApproval ? 'pending_approval' : 'approved',
                'afip_status' => 'approved',
                'approvals_required' => $company->required_approvals,
                'approvals_received' => $requiresApproval ? 0 : $company->required_approvals,
                'approval_date' => $requiresApproval ? null : now(),
                'created_by' => auth()->id(),
            ]);

            // Crear items
            foreach ($validated['items'] as $index => $item) {
                $itemSubtotal = $item['quantity'] * $item['unit_price'];
                $itemTax = $itemSubtotal * ($item['tax_rate'] / 100);

                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'],
                    'tax_amount' => $itemTax,
                    'subtotal' => $itemSubtotal,
                    'order_index' => $index,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Received invoice created successfully',
                'invoice' => $invoice->load(['supplier', 'items']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Received invoice creation failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create received invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function uploadAttachment(Request $request, $companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)
            ->orWhere('receiver_company_id', $companyId)
            ->findOrFail($id);

        $this->authorize('update', $invoice);

        $request->validate([
            'attachment' => 'required|file|mimes:pdf|max:10240',
        ]);

        $file = $request->file('attachment');
        $originalName = $file->getClientOriginalName();
        $path = $file->store('invoices/attachments', 'public');

        $invoice->update([
            'attachment_path' => $path,
            'attachment_original_name' => $originalName,
        ]);

        return response()->json([
            'message' => 'Attachment uploaded successfully',
            'attachment' => [
                'path' => $path,
                'original_name' => $originalName,
                'url' => asset('storage/' . $path),
            ],
        ]);
    }

    public function downloadAttachment($companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)
            ->orWhere('receiver_company_id', $companyId)
            ->findOrFail($id);

        $this->authorize('view', $invoice);

        if (!$invoice->attachment_path) {
            return response()->json(['message' => 'No attachment found'], 404);
        }

        $filePath = storage_path('app/public/' . $invoice->attachment_path);

        if (!file_exists($filePath)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return response()->download($filePath, $invoice->attachment_original_name);
    }

    public function deleteAttachment($companyId, $id)
    {
        $invoice = Invoice::where('issuer_company_id', $companyId)
            ->orWhere('receiver_company_id', $companyId)
            ->findOrFail($id);

        $this->authorize('update', $invoice);

        if ($invoice->attachment_path) {
            \Storage::disk('public')->delete($invoice->attachment_path);
        }

        $invoice->update([
            'attachment_path' => null,
            'attachment_original_name' => null,
        ]);

        return response()->json(['message' => 'Attachment deleted successfully']);
    }

    public function downloadPDF($companyId, $id)
    {
        $invoice = Invoice::with(['client', 'items'])->findOrFail($id);

        // Si tiene PDF generado con CAE
        if ($invoice->pdf_url) {
            $filePath = storage_path('app/' . $invoice->pdf_url);
            if (file_exists($filePath)) {
                return response()->download($filePath, "factura-{$invoice->number}.pdf");
            }
        }
        
        // Si tiene PDF adjunto
        if ($invoice->attachment_path) {
            $filePath = storage_path('app/public/' . $invoice->attachment_path);
            if (file_exists($filePath)) {
                return response()->download($filePath, $invoice->attachment_original_name);
            }
        }

        // Generar PDF on-demand
        $pdfService = new \App\Services\InvoicePdfService();
        $pdfPath = $pdfService->generatePdf($invoice);
        $filePath = storage_path('app/' . $pdfPath);
        return response()->download($filePath, "factura-{$invoice->number}.pdf");
    }

    public function downloadTXT($companyId, $id)
    {
        $invoice = Invoice::with(['client', 'items'])->findOrFail($id);

        // Si tiene TXT generado
        if ($invoice->afip_txt_url) {
            $filePath = storage_path('app/' . $invoice->afip_txt_url);
            if (file_exists($filePath)) {
                return response()->download($filePath, "factura-{$invoice->number}.txt");
            }
        }
        
        // Generar TXT on-demand
        $pdfService = new \App\Services\InvoicePdfService();
        $txtPath = $pdfService->generateTxt($invoice);
        $filePath = storage_path('app/' . $txtPath);
        return response()->download($filePath, "factura-{$invoice->number}.txt");
    }

    private function generateSimplePDF($invoice)
    {
        // Generar PDF simple con texto plano (sin librería)
        $content = "FACTURA " . $invoice->type . "\n";
        $content .= "Número: " . $invoice->number . "\n";
        $content .= "Fecha: " . $invoice->issue_date->format('d/m/Y') . "\n";
        $content .= "Cliente: " . ($invoice->client->business_name ?? $invoice->client->first_name . ' ' . $invoice->client->last_name) . "\n";
        $content .= "CUIT/DNI: " . $invoice->client->document_number . "\n\n";
        $content .= "ITEMS:\n";
        foreach ($invoice->items as $item) {
            $content .= "- " . $item->description . " x" . $item->quantity . " = $" . number_format($item->subtotal, 2) . "\n";
        }
        $content .= "\nSubtotal: $" . number_format($invoice->subtotal, 2) . "\n";
        $content .= "IVA: $" . number_format($invoice->total_taxes, 2) . "\n";
        $content .= "TOTAL: $" . number_format($invoice->total, 2) . "\n";
        if ($invoice->afip_cae) {
            $content .= "\nCAE: " . $invoice->afip_cae . "\n";
            $content .= "Vto CAE: " . $invoice->afip_cae_due_date->format('d/m/Y') . "\n";
        }
        return $content;
    }

    private function generateTXT($invoice)
    {
        // Formato TXT para AFIP/ARCA
        $lines = [];
        $lines[] = $invoice->number;
        $lines[] = $invoice->type;
        $lines[] = $invoice->issue_date->format('Ymd');
        $lines[] = $invoice->client->document_type . '|' . $invoice->client->document_number;
        $lines[] = number_format($invoice->subtotal, 2, '.', '');
        $lines[] = number_format($invoice->total_taxes, 2, '.', '');
        $lines[] = number_format($invoice->total, 2, '.', '');
        if ($invoice->afip_cae) {
            $lines[] = $invoice->afip_cae;
        }
        return implode("\n", $lines);
    }
}
