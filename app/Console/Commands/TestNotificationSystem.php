<?php

namespace App\Console\Commands;

use App\Jobs\SendInvoiceDueRemindersJob;
use App\Jobs\SendPaymentRemindersJob;
use App\Models\Invoice;
use App\Models\Company;
use App\Models\CompanyConnection;
use App\Models\User;
use App\Services\NotificationService;
use App\Helpers\NotificationHelper;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class TestNotificationSystem extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:test {--type=all : Type of test to run (all, jobs, helpers, manual)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the notification system functionality';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $testType = $this->option('type');
        
        $this->info('🧪 Testing Notification System...');
        
        switch ($testType) {
            case 'all':
                $this->testAll();
                break;
            case 'jobs':
                $this->testJobs();
                break;
            case 'helpers':
                $this->testHelpers();
                break;
            case 'manual':
                $this->testManualCreation();
                break;
            default:
                $this->error("Invalid test type: {$testType}");
                return 1;
        }
        
        $this->info('✅ Notification system test completed!');
        return 0;
    }

    private function testAll()
    {
        $this->testJobs();
        $this->testHelpers();
        $this->testManualCreation();
    }

    private function testJobs()
    {
        $this->info('🔧 Testing Notification Jobs...');
        
        // Test SendInvoiceDueRemindersJob
        $this->info('Testing SendInvoiceDueRemindersJob...');
        try {
            SendInvoiceDueRemindersJob::dispatch();
            $this->info('✅ SendInvoiceDueRemindersJob dispatched successfully');
        } catch (\Exception $e) {
            $this->error('❌ SendInvoiceDueRemindersJob failed: ' . $e->getMessage());
        }
        
        // Test SendPaymentRemindersJob
        $this->info('Testing SendPaymentRemindersJob...');
        try {
            SendPaymentRemindersJob::dispatch();
            $this->info('✅ SendPaymentRemindersJob dispatched successfully');
        } catch (\Exception $e) {
            $this->error('❌ SendPaymentRemindersJob failed: ' . $e->getMessage());
        }
    }

    private function testHelpers()
    {
        $this->info('🔧 Testing Notification Helpers...');
        
        // Create test data if it doesn't exist
        $company = Company::first() ?? Company::factory()->create();
        $user = User::first() ?? User::factory()->create();
        $invoice = Invoice::first() ?? Invoice::factory()->create([
            'issuer_company_id' => $company->id,
            'receiver_company_id' => $company->id,
            'number' => 'TEST-001',
            'balance_pending' => 1000,
            'due_date' => Carbon::now()->addDays(7),
            'payment_due_date' => Carbon::now()->addDays(14),
        ]);
        
        // Create a test payment
        $payment = Payment::first() ?? Payment::create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'payment_date' => Carbon::now(),
            'payment_method' => 'transfer',
            'status' => 'confirmed',
            'registered_by' => $user->id,
        ]);
        
        // Test different notification types with proper parameters
        $tests = [
            'notifyInvoiceReceived' => function() use ($invoice) {
                NotificationHelper::notifyInvoiceReceived($invoice);
            },
            'notifyInvoicePendingApproval' => function() use ($invoice) {
                NotificationHelper::notifyInvoicePendingApproval($invoice);
            },
            'notifyPaymentReceived' => function() use ($payment) {
                NotificationHelper::notifyPaymentReceived($payment);
            },
            'notifyConnectionRequest' => function() use ($user, $company) {
                // Create a connection request
                $connectedCompany = Company::create([
                    'name' => 'Connected Company Test',
                    'business_name' => 'Connected Company Test',
                    'tax_id' => 'CONN123456',
                    'national_id' => 'CON' . substr(uniqid(), -5),
                    'deletion_code' => 'DEL' . uniqid(),
                    'email' => 'connected@test.com',
                ]);
                $connection = CompanyConnection::create([
                    'company_id' => $company->id,
                    'connected_company_id' => $connectedCompany->id,
                    'status' => 'pending_sent',
                    'message' => 'Test connection request',
                    'requested_by' => $user->id,
                ]);
                NotificationHelper::notifyConnectionRequest($connection, $company);
            },
            'notifyInvoiceStatusChanged' => function() use ($invoice) {
                NotificationHelper::notifyInvoiceStatusChanged($invoice, 'approved', 'pending');
            },
            'notifyPaymentStatusChanged' => function() use ($payment) {
                NotificationHelper::notifyPaymentStatusChanged($payment, 'confirmed', 'pending');
            },
            'notifyInvoiceDueSoon' => function() use ($invoice) {
                NotificationHelper::notifyInvoiceDueSoon($invoice, 3);
            },
            'notifyInvoiceOverdue' => function() use ($invoice) {
                NotificationHelper::notifyInvoiceOverdue($invoice, 5);
            },
            'notifyConnectionAccepted' => function() use ($user, $company) {
                // Create a connection that was accepted
                $connectedCompany = Company::create([
                    'name' => 'Accepted Company Test',
                    'business_name' => 'Accepted Company Test',
                    'tax_id' => 'ACCEPT123',
                    'national_id' => 'ACC' . substr(uniqid(), -5),
                    'deletion_code' => 'DEL' . uniqid(),
                    'email' => 'accepted@test.com',
                ]);
                $connection = CompanyConnection::create([
                    'company_id' => $company->id,
                    'connected_company_id' => $connectedCompany->id,
                    'status' => 'connected',
                    'message' => 'Test connection request',
                    'requested_by' => $user->id,
                ]);
                NotificationHelper::notifyConnectionAccepted($connection);
            },
            'notifyConnectionRejected' => function() use ($user, $company) {
                // Create a connection that was rejected
                $connectedCompany = Company::create([
                    'name' => 'Rejected Company Test',
                    'business_name' => 'Rejected Company Test',
                    'tax_id' => 'REJECT123',
                    'national_id' => 'REJ' . substr(uniqid(), -5),
                    'deletion_code' => 'DEL' . uniqid(),
                    'email' => 'rejected@test.com',
                ]);
                $connection = CompanyConnection::create([
                    'company_id' => $company->id,
                    'connected_company_id' => $connectedCompany->id,
                    'status' => 'blocked',
                    'message' => 'Test connection request',
                    'requested_by' => $user->id,
                ]);
                NotificationHelper::notifyConnectionRejected($connection);
            },
            'notifySystemAlert' => function() use ($company) {
                NotificationHelper::notifySystemAlert($company->id, 'System Alert', 'This is a test system alert');
            },
            'notifyInvoiceNeedsReview' => function() use ($invoice) {
                NotificationHelper::notifyInvoiceNeedsReview($invoice, 'Missing required documentation');
            },
        ];
        
        foreach ($tests as $method => $testFunction) {
            try {
                $testFunction();
                $this->info("✅ {$method} executed successfully");
            } catch (\Exception $e) {
                $this->error("❌ {$method} failed: " . $e->getMessage());
            }
        }
    }

    private function testManualCreation()
    {
        $this->info('🔧 Testing Manual Notification Creation...');
        
        $company = Company::first() ?? Company::factory()->create();
        $notificationService = app(NotificationService::class);
        
        try {
            // Test creating notification for company members
            $notificationService->createForAllCompanyMembers(
                $company->id,
                'system_alert',
                'Test Notification',
                'This is a test notification created manually',
                [
                    'entityType' => 'test',
                    'entityId' => 'test-123',
                    'testData' => 'test-value'
                ]
            );
            $this->info('✅ Manual notification creation successful');
            
            // Test creating notification for specific user
            $user = User::first() ?? User::factory()->create();
            $notificationService->createForUser(
                $user->id,
                $company->id,
                'test_notification',
                'Test User Notification',
                'This is a test notification for a specific user',
                ['test' => 'data']
            );
            $this->info('✅ Manual user notification creation successful');
            
        } catch (\Exception $e) {
            $this->error('❌ Manual notification creation failed: ' . $e->getMessage());
        }
    }
}