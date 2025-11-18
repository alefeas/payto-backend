<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create();
        
        // Add user to company
        $this->company->members()->create([
            'user_id' => $this->user->id,
            'role' => 'owner',
        ]);
    }

    public function test_index_returns_suppliers()
    {
        Supplier::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/companies/{$this->company->id}/suppliers");

        expect($response->status())->toBe(200);
        expect($response->json())->toHaveCount(1);
    }

    public function test_index_excludes_trashed_suppliers()
    {
        Supplier::factory()->create(['company_id' => $this->company->id]);
        $trashed = Supplier::factory()->create(['company_id' => $this->company->id]);
        $trashed->delete();

        $response = $this->actingAs($this->user)
            ->getJson("/api/companies/{$this->company->id}/suppliers");

        expect($response->status())->toBe(200);
        expect($response->json())->toHaveCount(1);
    }

    public function test_archived_returns_trashed_suppliers()
    {
        Supplier::factory()->create(['company_id' => $this->company->id]);
        $trashed = Supplier::factory()->create(['company_id' => $this->company->id]);
        $trashed->delete();

        $response = $this->actingAs($this->user)
            ->getJson("/api/companies/{$this->company->id}/suppliers/archived");

        expect($response->status())->toBe(200);
        expect($response->json())->toHaveCount(1);
    }

    public function test_store_creates_supplier()
    {
        $data = [
            'document_type' => 'CUIT',
            'document_number' => '20123456789',
            'business_name' => 'Test Supplier',
            'email' => 'supplier@example.com',
            'tax_condition' => 'registered_taxpayer',
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/companies/{$this->company->id}/suppliers", $data);

        expect($response->status())->toBe(201);
        expect(Supplier::where('company_id', $this->company->id)->count())->toBe(1);
    }

    public function test_store_prevents_duplicate_document()
    {
        Supplier::factory()->create([
            'company_id' => $this->company->id,
            'document_number' => '20123456789',
        ]);

        $data = [
            'document_type' => 'CUIT',
            'document_number' => '20123456789',
            'business_name' => 'Another Supplier',
            'email' => 'another@example.com',
            'tax_condition' => 'registered_taxpayer',
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/companies/{$this->company->id}/suppliers", $data);

        expect($response->status())->toBe(422);
    }

    public function test_update_supplier()
    {
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/companies/{$this->company->id}/suppliers/{$supplier->id}", [
                'business_name' => 'Updated Name',
                'tax_condition' => 'registered_taxpayer',
            ]);

        expect($response->status())->toBe(200);
        expect(Supplier::find($supplier->id)->business_name)->toBe('Updated Name');
    }

    public function test_destroy_soft_deletes_supplier()
    {
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/companies/{$this->company->id}/suppliers/{$supplier->id}");

        expect($response->status())->toBe(200);
        expect(Supplier::find($supplier->id))->toBeNull();
        expect(Supplier::withTrashed()->find($supplier->id)->trashed())->toBeTrue();
    }

    public function test_restore_supplier()
    {
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        $supplier->delete();

        $response = $this->actingAs($this->user)
            ->postJson("/api/companies/{$this->company->id}/suppliers/{$supplier->id}/restore");

        expect($response->status())->toBe(200);
        expect(Supplier::find($supplier->id)->trashed())->toBeFalse();
    }

    public function test_store_requires_contact_info()
    {
        $data = [
            'document_type' => 'CUIT',
            'document_number' => '20123456789',
            'business_name' => 'Test Supplier',
            'tax_condition' => 'registered_taxpayer',
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/companies/{$this->company->id}/suppliers", $data);

        expect($response->status())->toBe(422);
    }
}
