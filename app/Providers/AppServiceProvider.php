<?php

namespace App\Providers;

use App\Interfaces\AuthServiceInterface;
use App\Interfaces\CompanyServiceInterface;
use App\Services\AuthService;
use App\Services\CompanyService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuthServiceInterface::class, AuthService::class);
        $this->app->bind(CompanyServiceInterface::class, CompanyService::class);
    }

    public function boot(): void
    {
        //
    }
}
