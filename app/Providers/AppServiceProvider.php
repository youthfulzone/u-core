<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // AnafSpvService now has no dependencies - uses session cookies only
        $this->app->singleton(\App\Services\AnafSpvService::class);
        
        // TargetareApiService for primary company data fetching
        $this->app->singleton(\App\Services\TargetareApiService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
