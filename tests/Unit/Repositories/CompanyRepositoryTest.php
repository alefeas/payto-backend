<?php

namespace Tests\Unit\Repositories;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Repositories\CompanyRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private CompanyRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(CompanyRepository::class);
    }

    public function test_get_by_user_returns_companies()
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        CompanyMember::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $result = $this->repository->getByUser($user->id);

        expect($result)->toHaveCount(1);
        expect($result->first()->id)->toBe($company->id);
    }

    public function test_get_by_user_returns_empty_for_user_without_companies()
    {
        $user = User::factory()->create();

        $result = $this->repository->getByUser($user->id);

        expect($result)->toHaveCount(0);
    }

    public function test_find_by_id_with_relations()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        CompanyMember::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $result = $this->repository->findByIdWithRelations($company->id, ['members']);

        expect($result->id)->toBe($company->id);
        expect($result->members)->toHaveCount(1);
    }

    public function test_find_by_id_with_relations_returns_null_for_non_existing()
    {
        $result = $this->repository->findByIdWithRelations('non-existing-id', ['members']);

        expect($result)->toBeNull();
    }

    public function test_get_with_members_and_certificates()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        CompanyMember::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $result = $this->repository->getWithMembersAndCertificates($company->id);

        expect($result->id)->toBe($company->id);
        expect($result->members)->toHaveCount(1);
    }

    public function test_check_duplicate_cuit_returns_true_for_existing()
    {
        Company::factory()->create(['cuit' => '20123456789']);

        $result = $this->repository->checkDuplicateCuit('20123456789');

        expect($result)->toBeTrue();
    }

    public function test_check_duplicate_cuit_returns_false_for_non_existing()
    {
        $result = $this->repository->checkDuplicateCuit('20123456789');

        expect($result)->toBeFalse();
    }

    public function test_check_duplicate_cuit_excludes_id()
    {
        $company = Company::factory()->create(['cuit' => '20123456789']);

        $result = $this->repository->checkDuplicateCuit('20123456789', $company->id);

        expect($result)->toBeFalse();
    }

    public function test_find_returns_company()
    {
        $company = Company::factory()->create();

        $result = $this->repository->find($company->id);

        expect($result->id)->toBe($company->id);
    }

    public function test_find_returns_null_for_non_existing()
    {
        $result = $this->repository->find('non-existing-id');

        expect($result)->toBeNull();
    }

    public function test_create_company()
    {
        $data = [
            'name' => 'Test Company',
            'cuit' => '20123456789',
            'business_name' => 'Test Business',
        ];

        $result = $this->repository->create($data);

        expect($result->name)->toBe('Test Company');
        expect($result->cuit)->toBe('20123456789');
    }

    public function test_update_company()
    {
        $company = Company::factory()->create();

        $result = $this->repository->update($company->id, ['name' => 'Updated Name']);

        expect($result)->toBeTrue();
        expect(Company::find($company->id)->name)->toBe('Updated Name');
    }

    public function test_delete_company()
    {
        $company = Company::factory()->create();

        $result = $this->repository->delete($company->id);

        expect($result)->toBeTrue();
        expect(Company::find($company->id))->toBeNull();
    }
}
