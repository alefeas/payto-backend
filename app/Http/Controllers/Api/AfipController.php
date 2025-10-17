<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Afip\AfipInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AfipController extends Controller
{
    public function authorizeInvoice(Request $request, Invoice $invoice)
    {
        $company = Auth::user()->company;

        if ($invoice->company_id !== $company->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($invoice->cae) {
            return response()->json(['error' => 'Invoice already authorized'], 400);
        }

        try {
            $afipService = new AfipInvoiceService($company);
            $result = $afipService->authorizeInvoice($invoice);

            $invoice->update([
                'cae' => $result['cae'],
                'cae_expiration' => $result['cae_expiration'],
                'afip_result' => $result['afip_result'],
                'status' => 'authorized',
            ]);

            return response()->json([
                'message' => 'Invoice authorized successfully',
                'invoice' => $invoice->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getLastAuthorizedNumber(Request $request)
    {
        $request->validate([
            'sales_point' => 'required|integer',
            'invoice_type' => 'required|string',
        ]);

        $company = Auth::user()->company;

        try {
            $afipService = new AfipInvoiceService($company);
            $invoiceType = \App\Services\VoucherTypeService::getAfipCode($request->invoice_type);
            
            $lastNumber = $afipService->getLastAuthorizedInvoice(
                $request->sales_point,
                $invoiceType
            );

            return response()->json(['last_number' => $lastNumber]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function testConnection()
    {
        $company = Auth::user()->company;

        try {
            $afipService = new AfipInvoiceService($company);
            $result = $afipService->testConnection();

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function consultInvoice(Request $request)
    {
        $request->validate([
            'invoice_type' => 'required|string',
            'sales_point' => 'required|integer',
            'voucher_number' => 'required|integer',
        ]);

        $company = Auth::user()->company;

        try {
            $afipService = new AfipInvoiceService($company);
            $invoiceType = \App\Services\VoucherTypeService::getAfipCode($request->invoice_type);
            
            $result = $afipService->consultInvoice(
                $company->national_id,
                $invoiceType,
                $request->sales_point,
                $request->voucher_number
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
