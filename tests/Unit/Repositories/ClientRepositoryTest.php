<?php

namespace Tests\Unit\Repositories;

use App\Models\Client;
use App\Models\Company;
use App\Repositories\ClientRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ClientRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(ClientRepository::class);
    }

    public function test_get_by_company_id_returns_clients()
    {
        $company = Company::factory()->create();
        $client = Client::factory()->create(['company_id' => $company->id]);

        $result = $this->repository->getByCompanyId($company->id);

        expect($result)->toHaveCount(1);
        expect($result->first()->id)->toBe($client->id);
    }

    public function test_get_by_company_id_excludes_trashed()
    {
        $company = Company::factory()->create();
        
        Client::factory()->create(['company_id' => $company->id]);
        Client::factory()->create(['company_id' => $company->id])->delete();

        $result = $this->repository->getByCompanyId($company->id);

        expect($result)->toHaveCount(1);
    }

    public function test_get_trashed_by_company_id()
    {
        $company = Company::factory()->create();
        
        Client::factory()->create(['company_id' => $company->id]);
        $trashed = Client::factory()->create(['company_id' => $company->id]);
        $trashed->delete();

        $result = $this->repository->getTrashedByCompanyId($company->id);

        expect($result)->toHaveCount(1);
        expect($result->first()->id)->toBe($trashed->id);
    }

    public function test_check_duplicate_document_returns_true_for_existing()
    {
        $company = Company::factory()->create();
        Client::factory()->create([
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
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'document_number' => '20123456789',
        ]);

        $result = $this->repository->checkDuplicateDocument($company->id, '20123456789', $client->id);

        expect($result)->toBeFalse();
    }

    public function test_check_duplicate_document_includes_trashed()
    {
        $company = Company::factory()->create();
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'document_number' => '20123456789',
        ]);
        $client->delete();

        $result = $this->repository->checkDuplicateDocument($company->id, '20123456789');

        expect($result)->toBeTrue();
    }

    public function test_find_by_document_and_company()
    {
        $company = Company::factory()->create();
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'document_number' => '20123456789',
        ]);

        $result = $this->repository->findByDocumentAndCompany($company->id, '20123456789');

        expect($result->id)->toBe($client->id);
    }

    public function test_find_by_document_and_company_includes_trashed()
    {
        $company = Company::factory()->create();
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'document_number' => '20123456789',
        ]);
        $client->delete();

        $result = $this->repository->findByDocumentAndCompany($company->id, '20123456789');

        expect($result->id)->toBe($client->id);
    }

    public function test_restore_client()
    {
        $company = Company::factory()->create();
        $client = Client::factory()->create(['company_id' => $company->id]);
        $client->delete();

        $result = $this->repository->restore($client->id);

        expect($result)->toBeTrue();
        expect(Client::find($client->id)->trashed())->toBeFalse();
    }

    public function test_restore_non_existing_client_returns_false()
    {
        $result = $this->repository->restore('non-existing-id');

        expect($result)->toBeFalse();
    }

    public function test_get_by_company_id_returns_empty_for_different_company()
    {
        $company1 = Company::factory()->create();
        $company2 = Company::factory()->create();
        
        Client::factory()->create(['company_id' => $company1->id]);

        $result = $this->repository->getByCompanyId($company2->id);

        expect($result)->toHaveCount(0);
    }
}
