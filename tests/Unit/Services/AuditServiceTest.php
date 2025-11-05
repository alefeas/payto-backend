<?php

namespace Tests\Unit\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuditService $auditService;
    private AuditLogRepository $repository;
    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->repository = new AuditLogRepository();
        $this->auditService = new AuditService($this->repository);
        
        $this->company = Company::factory()->create();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_log_an_audit_entry()
    {
        $auditLog = $this->auditService->log(
            $this->company->id,
            $this->user->id,
            'invoice_created',
            'Invoice created successfully',
            'Invoice',
            '123e4567-e89b-12d3-a456-426614174000',
            ['amount' => 1000, 'client' => 'John Doe'],
            '192.168.1.1',
            'Mozilla/5.0'
        );

        $this->assertInstanceOf(AuditLog::class, $auditLog);
        $this->assertEquals($this->company->id, $auditLog->company_id);
        $this->assertEquals($this->user->id, $auditLog->user_id);
        $this->assertEquals('invoice_created', $auditLog->action);
        $this->assertEquals('Invoice created successfully', $auditLog->description);
        $this->assertEquals('Invoice', $auditLog->entity_type);
        $this->assertEquals('123e4567-e89b-12d3-a456-426614174000', $auditLog->entity_id);
        $this->assertEquals(['amount' => 1000, 'client' => 'John Doe'], $auditLog->metadata);
        $this->assertEquals('192.168.1.1', $auditLog->ip_address);
        $this->assertEquals('Mozilla/5.0', $auditLog->user_agent);
    }

    /** @test */
    public function it_can_get_company_logs_with_pagination()
    {
        // Create test audit logs
        AuditLog::factory()->count(15)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $logs = $this->auditService->getCompanyLogs($this->company->id, 10);

        $this->assertEquals(15, $logs->total());
        $this->assertEquals(10, $logs->perPage());
        $this->assertEquals(1, $logs->currentPage());
    }

    /** @test */
    public function it_can_get_company_audit_statistics()
    {
        // Create test audit logs with different actions
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

        $stats = $this->auditService->getCompanyStats($this->company->id);

        $this->assertEquals(8, $stats['total_logs']);
        $this->assertEquals(2, $stats['unique_actions']);
        $this->assertEquals(1, $stats['unique_users']);
        $this->assertArrayHasKey('action_breakdown', $stats);
        $this->assertEquals(5, $stats['action_breakdown']['invoice_created']);
        $this->assertEquals(3, $stats['action_breakdown']['invoice_updated']);
    }

    /** @test */
    public function it_can_get_entity_logs()
    {
        $entityId = '123e4567-e89b-12d3-a456-426614174000';
        
        AuditLog::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'entity_type' => 'Invoice',
            'entity_id' => $entityId,
        ]);

        $logs = $this->auditService->getEntityLogs($this->company->id, 'Invoice', $entityId);

        $this->assertCount(3, $logs);
    }

    /** @test */
    public function it_can_get_user_logs()
    {
        AuditLog::factory()->count(4)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $logs = $this->auditService->getUserLogs($this->company->id, $this->user->id);

        $this->assertCount(4, $logs);
    }

    /** @test */
    public function it_can_get_recent_activities()
    {
        AuditLog::factory()->count(6)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $activities = $this->auditService->getRecentActivities($this->company->id, 5);

        $this->assertCount(5, $activities);
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

        $trail = $this->auditService->getAuditTrail($this->company->id, 'Invoice', $entityId);

        $this->assertCount(2, $trail);
        $this->assertEquals('invoice_created', $trail[1]->action);
        $this->assertEquals('invoice_updated', $trail[0]->action);
    }

    /** @test */
    public function it_can_export_logs_to_csv()
    {
        AuditLog::factory()->count(5)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->auditService->exportToCsv($this->company->id);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertEquals('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=', $response->headers->get('Content-Disposition'));
    }

    /** @test */
    public function it_can_filter_company_logs_by_action()
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

        $logs = $this->auditService->getCompanyLogs($this->company->id, 10, [
            'action' => 'invoice_created'
        ]);

        $this->assertEquals(3, $logs->total());
    }

    /** @test */
    public function it_can_filter_company_logs_by_date_range()
    {
        // Create logs in different date ranges
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

        $logs = $this->auditService->getCompanyLogs($this->company->id, 10, [
            'start_date' => now()->subDays(7)->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
        ]);

        $this->assertEquals(2, $logs->total());
    }
}