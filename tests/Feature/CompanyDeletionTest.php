<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Invoice;
use App\Models\Address;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

class CompanyDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $companyA;
    private Company $companyB;
    private string $deletionCode = 'TEST123';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create user
        $this->user = User::factory()->create();
        
        // Create Company A (will be deleted)
        $this->companyA = Company::create([
            'name' => 'Company A',
            'business_name' => 'Company A SA',
            'national_id' => '20123456789',
            'tax_condition' => 'registered_taxpayer',
            'deletion_code' => Hash::make($this->deletionCode),
            'unique_id' => 'COMPA001',
            'invite_code' => 'INVITE001',
            'is_active' => true,
        ]);
        
        Address::create([
            'company_id' => $this->companyA->id,
            'street' => 'Calle A',
            'street_number' => '123',
            'city' => 'Buenos Aires',
            'province' => 'Buenos Aires',
            'postal_code' => '1000',
        ]);
        
        CompanyMember::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->user->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
        
        // Create Company B (will receive/issue invoices)
        $this->companyB = Company::create([
            'name' => 'Company B',
            'business_name' => 'Company B SA',
            'national_id' => '20987654321',
            'tax_condition' => 'registered_taxpayer',
            'deletion_code' => Hash::make('OTHER123'),
            'unique_id' => 'COMPB001',
            'invite_code' => 'INVITE002',
            'is_active' => true,
        ]);
        
        Address::create([
            'company_id' => $this->companyB->id,
            'street' => 'Calle B',
            'street_number' => '456',
            'city' => 'Córdoba',
            'province' => 'Córdoba',
            'postal_code' => '5000',
        ]);
        
        CompanyMember::create([
            'company_id' => $this->companyB->id,
            'user_id' => $this->user->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_converts_issued_invoices_to_suppliers_when_company_is_deleted()
    {
        // Company A emite factura a Company B
        $invoice = Invoice::create([
            'number' => '0001-00000001',
            'type' => 'A',
            'sales_point' => 1,
            'voucher_number' => 1,
            'concept' => 'products',
            'issuer_company_id' => $this->companyA->id,
            'receiver_company_id' => $this->companyB->id,
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 1000,
            'total_taxes' => 210,
            'total_perceptions' => 0,
            'total' => 1210,
            'currency' => 'ARS',
            'status' => 'issued',
            'afip_status' => 'approved',
            'created_by' => $this->user->id,
        ]);

        // Verificar estado inicial
        $this->assertNull($invoice->supplier_id);
        $this->assertEquals($this->companyA->id, $invoice->issuer_company_id);
        $this->assertEquals(0, \App\Models\Supplier::where('company_id', $this->companyB->id)->count());

        // Eliminar Company A
        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/companies/{$this->companyA->id}", [
                'deletion_code' => $this->deletionCode
            ]);

        $response->assertStatus(200);

        // Verificar que se creó el proveedor en Company B
        $supplier = \App\Models\Supplier::where('company_id', $this->companyB->id)
            ->where('document_number', '20123456789')
            ->first();
        
        $this->assertNotNull($supplier);
        $this->assertEquals('Company A', $supplier->business_name);
        $this->assertEquals('registered_taxpayer', $supplier->tax_condition);

        // Verificar que la factura se actualizó
        $invoice->refresh();
        $this->assertEquals($supplier->id, $invoice->supplier_id);
        $this->assertEquals('Company A', $invoice->issuer_name);
        $this->assertEquals('20123456789', $invoice->issuer_document);
        $this->assertTrue($invoice->manual_supplier);
    }

    /** @test */
    public function it_converts_received_invoices_to_clients_when_company_is_deleted()
    {
        // Company B emite factura a Company A
        $invoice = Invoice::create([
            'number' => '0001-00000001',
            'type' => 'A',
            'sales_point' => 1,
            'voucher_number' => 1,
            'concept' => 'products',
            'issuer_company_id' => $this->companyB->id,
            'receiver_company_id' => $this->companyA->id,
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 1000,
            'total_taxes' => 210,
            'total_perceptions' => 0,
            'total' => 1210,
            'currency' => 'ARS',
            'status' => 'issued',
            'afip_status' => 'approved',
            'created_by' => $this->user->id,
        ]);

        // Verificar estado inicial
        $this->assertNull($invoice->client_id);
        $this->assertEquals($this->companyA->id, $invoice->receiver_company_id);
        $this->assertEquals(0, \App\Models\Client::where('company_id', $this->companyB->id)->count());

        // Eliminar Company A
        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/companies/{$this->companyA->id}", [
                'deletion_code' => $this->deletionCode
            ]);

        $response->assertStatus(200);

        // Verificar que se creó el cliente en Company B
        $client = \App\Models\Client::where('company_id', $this->companyB->id)
            ->where('document_number', '20123456789')
            ->first();
        
        $this->assertNotNull($client);
        $this->assertEquals('Company A', $client->business_name);
        $this->assertEquals('registered_taxpayer', $client->tax_condition);

        // Verificar que la factura se actualizó
        $invoice->refresh();
        $this->assertEquals($client->id, $invoice->client_id);
        $this->assertEquals('Company A', $invoice->receiver_name);
        $this->assertEquals('20123456789', $invoice->receiver_document);
    }

    /** @test */
    public function it_handles_multiple_invoices_correctly()
    {
        // Company A emite 2 facturas a Company B
        Invoice::create([
            'number' => '0001-00000001',
            'type' => 'A',
            'sales_point' => 1,
            'voucher_number' => 1,
            'concept' => 'products',
            'issuer_company_id' => $this->companyA->id,
            'receiver_company_id' => $this->companyB->id,
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 1000,
            'total_taxes' => 210,
            'total' => 1210,
            'currency' => 'ARS',
            'status' => 'issued',
            'afip_status' => 'approved',
            'created_by' => $this->user->id,
        ]);

        Invoice::create([
            'number' => '0001-00000002',
            'type' => 'A',
            'sales_point' => 1,
            'voucher_number' => 2,
            'concept' => 'products',
            'issuer_company_id' => $this->companyA->id,
            'receiver_company_id' => $this->companyB->id,
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => 2000,
            'total_taxes' => 420,
            'total' => 2420,
            'currency' => 'ARS',
            'status' => 'issued',
            'afip_status' => 'approved',
            'created_by' => $this->user->id,
        ]);

        // Eliminar Company A
        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/companies/{$this->companyA->id}", [
                'deletion_code' => $this->deletionCode
            ]);

        $response->assertStatus(200);

        // Verificar que solo se creó UN proveedor (no duplicados)
        $suppliers = \App\Models\Supplier::where('company_id', $this->companyB->id)
            ->where('document_number', '20123456789')
            ->get();
        
        $this->assertEquals(1, $suppliers->count());

        // Verificar que ambas facturas apuntan al mismo proveedor
        $invoices = Invoice::where('supplier_id', $suppliers->first()->id)->get();
        $this->assertEquals(2, $invoices->count());
    }

    /** @test */
    public function it_fails_with_wrong_deletion_code()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/companies/{$this->companyA->id}", [
                'deletion_code' => 'WRONG_CODE'
            ]);

        $response->assertStatus(400);
        
        // Verificar que la empresa NO fue eliminada
        $this->assertDatabaseHas('companies', [
            'id' => $this->companyA->id,
        ]);
    }
}
