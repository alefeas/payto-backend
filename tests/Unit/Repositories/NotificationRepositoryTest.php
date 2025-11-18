<?php

namespace Tests\Unit\Repositories;

use App\Models\Company;
use App\Models\Notification;
use App\Repositories\NotificationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private NotificationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(NotificationRepository::class);
    }

    public function test_get_by_company_id_returns_notifications()
    {
        $company = Company::factory()->create();
        $notification = Notification::factory()->create(['company_id' => $company->id]);

        $result = $this->repository->getByCompanyId($company->id);

        expect($result)->toHaveCount(1);
        expect($result->first()->id)->toBe($notification->id);
    }

    public function test_get_by_company_id_with_limit()
    {
        $company = Company::factory()->create();
        Notification::factory(5)->create(['company_id' => $company->id]);

        $result = $this->repository->getByCompanyId($company->id, 3);

        expect($result)->toHaveCount(3);
    }

    public function test_get_by_company_id_ordered_by_created_at_desc()
    {
        $company = Company::factory()->create();
        
        $notification1 = Notification::factory()->create([
            'company_id' => $company->id,
            'created_at' => now()->subDays(2),
        ]);
        
        $notification2 = Notification::factory()->create([
            'company_id' => $company->id,
            'created_at' => now(),
        ]);

        $result = $this->repository->getByCompanyId($company->id);

        expect($result->first()->id)->toBe($notification2->id);
        expect($result->last()->id)->toBe($notification1->id);
    }

    public function test_get_unread_by_company_id()
    {
        $company = Company::factory()->create();
        
        Notification::factory()->create([
            'company_id' => $company->id,
            'read_at' => now(),
        ]);
        
        $unread = Notification::factory()->create([
            'company_id' => $company->id,
            'read_at' => null,
        ]);

        $result = $this->repository->getUnreadByCompanyId($company->id);

        expect($result)->toHaveCount(1);
        expect($result->first()->id)->toBe($unread->id);
    }

    public function test_get_unread_count_by_company_id()
    {
        $company = Company::factory()->create();
        
        Notification::factory(2)->create([
            'company_id' => $company->id,
            'read_at' => null,
        ]);
        
        Notification::factory()->create([
            'company_id' => $company->id,
            'read_at' => now(),
        ]);

        $count = $this->repository->getUnreadCountByCompanyId($company->id);

        expect($count)->toBe(2);
    }

    public function test_mark_as_read()
    {
        $company = Company::factory()->create();
        $notification = Notification::factory()->create([
            'company_id' => $company->id,
            'read_at' => null,
        ]);

        $result = $this->repository->markAsRead($notification->id);

        expect($result)->toBeTrue();
        expect(Notification::find($notification->id)->read_at)->not->toBeNull();
    }

    public function test_mark_all_as_read_by_company_id()
    {
        $company = Company::factory()->create();
        
        Notification::factory(3)->create([
            'company_id' => $company->id,
            'read_at' => null,
        ]);

        $result = $this->repository->markAllAsReadByCompanyId($company->id);

        expect($result)->toBe(3);
        expect(Notification::where('company_id', $company->id)->where('read_at', null)->count())->toBe(0);
    }

    public function test_delete_old_notifications()
    {
        $company = Company::factory()->create();
        
        Notification::factory()->create([
            'company_id' => $company->id,
            'created_at' => now()->subDays(40),
        ]);
        
        Notification::factory()->create([
            'company_id' => $company->id,
            'created_at' => now()->subDays(10),
        ]);

        $result = $this->repository->deleteOldNotifications($company->id, 30);

        expect($result)->toBe(1);
        expect(Notification::where('company_id', $company->id)->count())->toBe(1);
    }

    public function test_get_by_company_id_returns_empty_for_different_company()
    {
        $company1 = Company::factory()->create();
        $company2 = Company::factory()->create();
        
        Notification::factory()->create(['company_id' => $company1->id]);

        $result = $this->repository->getByCompanyId($company2->id);

        expect($result)->toHaveCount(0);
    }
}
