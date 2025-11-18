<?php

namespace Tests\Unit\Repositories;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Repositories\CompanyMemberRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyMemberRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private CompanyMemberRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(CompanyMemberRepository::class);
    }

    public function test_get_by_company_id_returns_members()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $member = CompanyMember::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $result = $this->repository->getByCompanyId($company->id);

        expect($result)->toHaveCount(1);
        expect($result->first()->id)->toBe($member->id);
    }

    public function test_get_by_company_id_and_user_id()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $member = CompanyMember::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $result = $this->repository->getByCompanyIdAndUserId($company->id, $user->id);

        expect($result->id)->toBe($member->id);
    }

    public function test_get_by_company_id_and_user_id_returns_null_for_non_existing()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $result = $this->repository->getByCompanyIdAndUserId($company->id, $user->id);

        expect($result)->toBeNull();
    }

    public function test_get_by_company_id_and_role()
    {
        $company = Company::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        
        CompanyMember::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user1->id,
            'role' => 'owner',
        ]);
        
        CompanyMember::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user2->id,
            'role' => 'member',
        ]);

        $result = $this->repository->getByCompanyIdAndRole($company->id, 'owner');

        expect($result)->toHaveCount(1);
        expect($result->first()->role)->toBe('owner');
    }

    public function test_check_member_exists_returns_true()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        CompanyMember::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $result = $this->repository->checkMemberExists($company->id, $user->id);

        expect($result)->toBeTrue();
    }

    public function test_check_member_exists_returns_false()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $result = $this->repository->checkMemberExists($company->id, $user->id);

        expect($result)->toBeFalse();
    }

    public function test_get_owner()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $owner = CompanyMember::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $result = $this->repository->getOwner($company->id);

        expect($result->id)->toBe($owner->id);
    }

    public function test_search_by_email()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['email' => 'test@example.com']);
        CompanyMember::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $result = $this->repository->searchByEmail($company->id, 'test@');

        expect($result)->toHaveCount(1);
        expect($result->first()->user->email)->toBe('test@example.com');
    }

    public function test_search_by_email_returns_empty_for_no_match()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['email' => 'test@example.com']);
        CompanyMember::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $result = $this->repository->searchByEmail($company->id, 'nomatch@');

        expect($result)->toHaveCount(0);
    }

    public function test_get_by_company_id_returns_empty_for_different_company()
    {
        $company1 = Company::factory()->create();
        $company2 = Company::factory()->create();
        $user = User::factory()->create();
        
        CompanyMember::factory()->create([
            'company_id' => $company1->id,
            'user_id' => $user->id,
        ]);

        $result = $this->repository->getByCompanyId($company2->id);

        expect($result)->toHaveCount(0);
    }
}
