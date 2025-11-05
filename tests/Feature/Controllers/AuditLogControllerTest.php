<?php

namespace Tests\Feature\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->company = Company::factory()->create();
        $this->user = User::factory()->create();
        
        Auth::login($this->user);
    }

    /** @test */
    public function it_can_list_company_audit_logs()
    {
        AuditLog::factory()->count(5)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/companies/{$this->company->id}/audit-logs");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'company_id',
                        'user_id',
                        'user',
                        'action',
                        'entity_type',
                        'entity_id',
                        'description',
                        'metadata',
                        'ip_address',
                        'user_agent',
                        'created_at',
                        'updated_at',
                        'formatted_date',
                        'relative_time',
                    ]
                ],
                'current_page',
                'total_pages',
                'total_items',
                'per_page',
            ]);
    }

    /** @test */
    public function it_can_filter_audit_logs_by_action()
    {
        AuditLog::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'action' => 'invoice_created',
        ]);
        
        AuditLog::factory()->count(2)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'action' => 'invoice_updated',
        ]);

        $response = $this->getJson("/api/companies/{$this->company->id}/audit-logs?action=invoice_created");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function it_can_filter_audit_logs_by_date_range()
    {
        AuditLog::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'created_at' => now()->subDays(10),
        ]);
        
        AuditLog::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'created_at' => now()->subDays(5),
        ]);
        
        AuditLog::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'created_at' => now()->subDay(),
        ]);

        $startDate = now()->subDays(7)->format('Y-m-d');
        $endDate = now()->format('Y-m-d');

        $response = $this->getJson("/api/companies/{$this->company->id}/audit-logs?start_date={$startDate}&end_date={$endDate}");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /** @test */
    public function it_can_get_audit_statistics()
    {
        AuditLog::factory()->count(5)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'action' => 'invoice_created',
        ]);
        
        AuditLog::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'action' => 'invoice_updated',
        ]);

        $response = $this->getJson("/api/companies/{$this->company->id}/audit-logs/stats");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_logs',
                'unique_actions',
                'unique_users',
                'action_breakdown',
            ])
            ->assertJson([
                'total_logs' => 8,
                'unique_actions' => 2,
                'unique_users' => 1,
            ]);
    }

    /** @test */
    public function it_can_get_entity_audit_logs()
    {
        $entityId = '123e4567-e89b-12d3-a456-426614174000';
        
        AuditLog::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'entity_type' => 'Invoice',
            'entity_id' => $entityId,
        ]);

        $response = $this->getJson("/api/companies/{$this->company->id}/audit-logs/entity/Invoice/{$entityId}");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function it_can_get_user_audit_logs()
    {
        AuditLog::factory()->count(4)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/companies/{$this->company->id}/audit-logs/user/{$this->user->id}");

        $response->assertStatus(200)
            ->assertJsonCount(4, 'data');
    }

    /** @test */
    public function it_can_get_recent_activities()
    {
        AuditLog::factory()->count(6)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/companies/{$this->company->id}/audit-logs/recent?limit=5");

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data');
    }

    /** @test */
    public function it_can_get_audit_trail()
    {
        $entityId = '123e4567-e89b-12d3-a456-426614174000';
        
        AuditLog::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'entity_type' => 'Invoice',
            'entity_id' => $entityId,
            'action' => 'invoice_created',
            'created_at' => now()->subDays(2),
        ]);
        
        AuditLog::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'entity_type' => 'Invoice',
            'entity_id' => $entityId,
            'action' => 'invoice_updated',
            'created_at' => now()->subDay(),
        ]);

        $response = $this->getJson("/api/companies/{$this->company->id}/audit-logs/trail/Invoice/{$entityId}");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /** @test */
    public function it_can_export_audit_logs_to_csv()
    {
        AuditLog::factory()->count(5)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/companies/{$this->company->id}/audit-logs/export");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename=audit_logs_' . $this->company->id . '_' . now()->format('Y-m-d_H-i-s') . '.csv');
    }

    /** @test */
    public function it_validates_invalid_date_range()
    {
        $response = $this->getJson("/api/companies/{$this->company->id}/audit-logs?start_date=2024-01-15&end_date=2024-01-10");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    /** @test */
    public function it_validates_invalid_pagination_limit()
    {
        $response = $this->getJson("/api/companies/{$this->company->id}/audit-logs?per_page=2000");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    /** @test */
    public function it_returns_empty_array_when_no_audit_logs_exist()
    {
        $response = $this->getJson("/api/companies/{$this->company->id}/audit-logs");

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJson([
                'total_items' => 0,
                'data' => []
            ]);
    }

    /** @test */
    public function it_handles_non_existent_company_gracefully()
    {
        $nonExistentCompanyId = '123e4567-e89b-12d3-a456-426614174000';

        $response = $this->getJson("/api/companies/{$nonExistentCompanyId}/audit-logs");

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}