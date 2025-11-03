<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceRefactoringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that InvoiceService is properly injected
     */
    public function test_invoice_service_is_available(): void
    {
        $service = app(\App\Services\InvoiceService::class);
        $this->assertInstanceOf(\App\Services\InvoiceService::class, $service);
    }

    /**
     * Test that InvoiceRepository is available
     */
    public function test_invoice_repository_is_available(): void
    {
        $repository = app(\App\Repositories\InvoiceRepository::class);
        $this->assertInstanceOf(\App\Repositories\InvoiceRepository::class, $repository);
    }

    /**
     * Test that Form Requests are available
     */
    public function test_form_requests_exist(): void
    {
        $this->assertTrue(class_exists(\App\Http\Requests\StoreInvoiceRequest::class));
        $this->assertTrue(class_exists(\App\Http\Requests\StoreManualIssuedInvoiceRequest::class));
        $this->assertTrue(class_exists(\App\Http\Requests\StoreManualReceivedInvoiceRequest::class));
        $this->assertTrue(class_exists(\App\Http\Requests\UpdateSyncedInvoiceRequest::class));
        $this->assertTrue(class_exists(\App\Http\Requests\SyncFromAfipRequest::class));
    }

    /**
     * Test that DTOs are available
     */
    public function test_dtos_exist(): void
    {
        $this->assertTrue(class_exists(\App\DTOs\InvoiceItemDTO::class));
        $this->assertTrue(class_exists(\App\DTOs\InvoicePerceptionDTO::class));
        $this->assertTrue(class_exists(\App\DTOs\CreateInvoiceDTO::class));
    }

    /**
     * Test that services are available
     */
    public function test_services_exist(): void
    {
        $this->assertTrue(class_exists(\App\Services\InvoiceCalculationService::class));
        $this->assertTrue(class_exists(\App\Services\CuitHelperService::class));
    }

    /**
     * Test invoice index endpoint still works (structure)
     */
    public function test_invoice_index_endpoint_structure(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        
        $response = $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company->id}/invoices");
        
        // Should return 200 or 403 (authorization), but not 500 (error)
        $this->assertNotEquals(500, $response->status());
    }
}

