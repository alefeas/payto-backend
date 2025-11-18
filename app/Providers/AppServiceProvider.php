<?php

namespace App\Providers;

use App\Interfaces\AuthServiceInterface;
use App\Interfaces\CompanyServiceInterface;
use App\Interfaces\CompanyMemberServiceInterface;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Collection;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\CompanySalesPoint;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\Notification;
use App\Observers\ModelAuditObserver;
use App\Models\CompanyConnection;
use App\Policies\ClientPolicy;
use App\Policies\CompanyConnectionPolicy;
use App\Repositories\CompanyRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\ClientRepository;
use App\Repositories\CompanyMemberRepository;
use App\Repositories\NotificationRepository;
use App\Services\AuthService;
use App\Services\CompanyService;
use App\Services\CompanyMemberService;
use App\Services\AuditService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditService::class);
        $this->app->bind(AuthServiceInterface::class, AuthService::class);
        $this->app->bind(CompanyServiceInterface::class, CompanyService::class);
        $this->app->bind(CompanyMemberServiceInterface::class, CompanyMemberService::class);

        // Repository bindings
        $this->app->bind(CompanyRepository::class, function ($app) {
            return new CompanyRepository(new Company());
        });
        $this->app->bind(PaymentRepository::class, function ($app) {
            return new PaymentRepository(new Payment());
        });
        $this->app->bind(SupplierRepository::class, function ($app) {
            return new SupplierRepository(new Supplier());
        });
        $this->app->bind(ClientRepository::class, function ($app) {
            return new ClientRepository(new Client());
        });
        $this->app->bind(CompanyMemberRepository::class, function ($app) {
            return new CompanyMemberRepository(new CompanyMember());
        });
        $this->app->bind(NotificationRepository::class, function ($app) {
            return new NotificationRepository(new Notification());
        });
    }

    public function boot(): void
    {
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(CompanyConnection::class, CompanyConnectionPolicy::class);

        // Registrar observer de auditoría para modelos con company_id
        Client::observe(ModelAuditObserver::class);
        Supplier::observe(ModelAuditObserver::class);
        Invoice::observe(ModelAuditObserver::class);
        Payment::observe(ModelAuditObserver::class);
        Collection::observe(ModelAuditObserver::class);
        CompanySalesPoint::observe(ModelAuditObserver::class);
        BankAccount::observe(ModelAuditObserver::class);
        Company::observe(ModelAuditObserver::class);
        CompanyMember::observe(ModelAuditObserver::class);
        Notification::observe(ModelAuditObserver::class);
    }
}
