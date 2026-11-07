<?php

namespace App\Providers;

use App\Services\Agent\AgentClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AgentClient::class, fn () => new AgentClient(
            config('agent.socket'),
            config('agent.timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
