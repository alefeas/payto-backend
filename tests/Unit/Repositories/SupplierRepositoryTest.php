<?php

namespace Tests\Unit\Repositories;

use App\Models\Company;
use App\Models\Supplier;
use App\Repositories\SupplierRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private SupplierRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(SupplierRepository::class);
    }

    public function test_get_by_company_id_returns_suppliers()
    {
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->create(['company_id' => $company->id]);

        $result = $this->repository->getByCompanyId($company->id);

        expect($result)->toHaveCount(1);
        expect($result->first()->id)->toBe($supplier->id);
    }

    public function test_get_by_company_id_excludes_trashed()
    {
        $company = Company::factory()->create();
        
        Supplier::factory()->create(['company_id' => $company->id]);
        Supplier::factory()->create(['company_id' => $company->id])->delete();

        $result = $this->repository->getByCompanyId($company->id);

        expect($result)->toHaveCount(1);
    }

    public function test_get_trashed_by_company_id()
    {
        $company = Company::factory()->create();
        
        Supplier::factory()->create(['company_id' => $company->id]);
        $trashed = Supplier::factory()->create(['company_id' => $company->id]);
        $trashed->delete();

        $result = $this->repository->getTrashedByCompanyId($company->id);

        expect($result)->toHaveCount(1);
        expect($result->first()->id)->toBe($trashed->id);
    }

    public function test_check_duplicate_document_returns_true_for_existing()
    {
        $company = Company::factory()->create();
        Supplier::factory()->create([
            'company_id' => $company->id,
            'document_number' => '20123456789',
        ]);

        $result = $this->repository->checkDuplicateDocument($company->id, '20123456789');

        expect($result)->toBeTrue();
    }

    public function test_check_duplicate_document_returns_false_for_non_existing()
    {
        $company = Company::factory()->create();

        $result = $this->repository->checkDuplicateDocument($company->id, '20123456789');

        expect($result)->toBeFalse();
    }

    public function test_check_duplicate_document_excludes_id()
    {
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->create([
            'company_id' => $company->id,
            'document_number' => '20123456789',
        ]);

        $result = $this->repository->checkDuplicateDocument($company->id, '20123456789', $supplier->id);

        expect($result)->toBeFalse();
    }

    public function test_check_duplicate_document_includes_trashed()
    {
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->create([
            'company_id' => $company->id,
            'document_number' => '20123456789',
        ]);
        $supplier->delete();

        $result = $this->repository->checkDuplicateDocument($company->id, '20123456789');

        expect($result)->toBeTrue();
    }

    public function test_find_by_document_and_company()
    {
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->create([
            'company_id' => $company->id,
            'document_number' => '20123456789',
        ]);

        $result = $this->repository->findByDocumentAndCompany($company->id, '20123456789');

        expect($result->id)->toBe($supplier->id);
    }

    public function test_find_by_document_and_company_includes_trashed()
    {
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->create([
            'company_id' => $company->id,
            'document_number' => '20123456789',
        ]);
        $supplier->delete();

        $result = $this->repository->findByDocumentAndCompany($company->id, '20123456789');

        expect($result->id)->toBe($supplier->id);
    }

    public function test_restore_supplier()
    {
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->create(['company_id' => $company->id]);
        $supplier->delete();

        $result = $this->repository->restore($supplier->id);

        expect($result)->toBeTrue();
        expect(Supplier::find($supplier->id)->trashed())->toBeFalse();
    }

    public function test_restore_non_existing_supplier_returns_false()
    {
        $result = $this->repository->restore('non-existing-id');

        expect($result)->toBeFalse();
    }

    public function test_get_by_company_id_returns_empty_for_different_company()
    {
        $company1 = Company::factory()->create();
        $company2 = Company::factory()->create();
        
        Supplier::factory()->create(['company_id' => $company1->id]);

        $result = $this->repository->getByCompanyId($company2->id);

        expect($result)->toHaveCount(0);
    }
}
