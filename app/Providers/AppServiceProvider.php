<?php

namespace App\Providers;

use App\Interfaces\AuthServiceInterface;
use App\Interfaces\CompanyServiceInterface;
use App\Interfaces\CompanyMemberServiceInterface;
use App\Models\Client;
use App\Models\CompanyConnection;
use App\Policies\ClientPolicy;
use App\Policies\CompanyConnectionPolicy;
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
    }

    public function boot(): void
    {
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(CompanyConnection::class, CompanyConnectionPolicy::class);
    }
}
