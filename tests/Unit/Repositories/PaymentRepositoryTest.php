<?php

namespace Tests\Unit\Repositories;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Supplier;
use App\Repositories\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private PaymentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(PaymentRepository::class);
    }

    public function test_get_by_company_id_returns_payments()
    {
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->create(['company_id' => $company->id]);
        $invoice = Invoice::factory()->create([
            'issuer_company_id' => $company->id,
            'supplier_id' => $supplier->id,
        ]);
        
        $payment = Payment::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
        ]);

        $result = $this->repository->getByCompanyId($company->id);

        expect($result)->toHaveCount(1);
        expect($result->first()->id)->toBe($payment->id);
    }

    public function test_get_by_company_id_with_status_filter()
    {
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->create(['company_id' => $company->id]);
        $invoice = Invoice::factory()->create([
            'issuer_company_id' => $company->id,
            'supplier_id' => $supplier->id,
        ]);
        
        Payment::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'status' => 'confirmed',
        ]);
        
        Payment::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'status' => 'pending',
        ]);

        $result = $this->repository->getByCompanyId($company->id, ['status' => 'confirmed']);

        expect($result)->toHaveCount(1);
        expect($result->first()->status)->toBe('confirmed');
    }

    public function test_get_by_company_id_with_date_filters()
    {
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->create(['company_id' => $company->id]);
        $invoice = Invoice::factory()->create([
            'issuer_company_id' => $company->id,
            'supplier_id' => $supplier->id,
        ]);
        
        Payment::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'payment_date' => now()->subDays(10),
        ]);
        
        Payment::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'payment_date' => now(),
        ]);

        $result = $this->repository->getByCompanyId($company->id, [
            'from_date' => now()->subDays(5),
        ]);

        expect($result)->toHaveCount(1);
    }

    public function test_get_total_paid_for_invoice()
    {
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->create(['company_id' => $company->id]);
        $invoice = Invoice::factory()->create([
            'issuer_company_id' => $company->id,
            'supplier_id' => $supplier->id,
        ]);
        
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'status' => 'confirmed',
        ]);
        
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 50,
            'status' => 'confirmed',
        ]);
        
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 25,
            'status' => 'pending',
        ]);

        $total = $this->repository->getTotalPaidForInvoice($invoice->id);

        expect($total)->toBe(150.0);
    }

    public function test_get_by_invoice_ids()
    {
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->create(['company_id' => $company->id]);
        
        $invoice1 = Invoice::factory()->create([
            'issuer_company_id' => $company->id,
            'supplier_id' => $supplier->id,
        ]);
        
        $invoice2 = Invoice::factory()->create([
            'issuer_company_id' => $company->id,
            'supplier_id' => $supplier->id,
        ]);
        
        $payment1 = Payment::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice1->id,
        ]);
        
        $payment2 = Payment::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice2->id,
        ]);

        $result = $this->repository->getByInvoiceIds([$invoice1->id, $invoice2->id], $company->id);

        expect($result)->toHaveCount(2);
        expect($result->pluck('id'))->toContain($payment1->id, $payment2->id);
    }

    public function test_get_supplier_payments_by_company()
    {
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->create(['company_id' => $company->id]);
        $invoice = Invoice::factory()->create([
            'issuer_company_id' => $company->id,
            'supplier_id' => $supplier->id,
        ]);
        
        $payment = Payment::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'status' => 'confirmed',
        ]);

        $result = $this->repository->getSupplierPaymentsByCompany($company->id, ['status' => 'confirmed']);

        expect($result)->toHaveCount(1);
        expect($result->first()->id)->toBe($payment->id);
    }

    public function test_get_by_company_id_returns_empty_for_different_company()
    {
        $company1 = Company::factory()->create();
        $company2 = Company::factory()->create();
        $supplier = Supplier::factory()->create(['company_id' => $company1->id]);
        $invoice = Invoice::factory()->create([
            'issuer_company_id' => $company1->id,
            'supplier_id' => $supplier->id,
        ]);
        
        Payment::factory()->create([
            'company_id' => $company1->id,
            'invoice_id' => $invoice->id,
        ]);

        $result = $this->repository->getByCompanyId($company2->id);

        expect($result)->toHaveCount(0);
    }
}
