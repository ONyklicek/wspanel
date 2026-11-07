<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationGroup;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationGroups;

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
        // The file manager uploads through Livewire: up to the panel's PHP limit (512 MB).
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:524288'], 'livewire.temporary_file_upload.max_upload_time' => 30]);

        // Numbers and sizes in the panel's language (1,5 GB, 12,5 %).
        Number::useLocale((string) config('app.locale'));

        // Administrators manage the server and the panel's accounts; clients
        // only what they own (narrowed by the OwnedByClient scope).
        Gate::define('admin', fn (User $user): bool => $user->isAdmin());
        foreach ((array) config('wire-module-users.permissions') as $abilities) {
            foreach ((array) $abilities as $ability) {
                Gate::define((string) $ability, fn (User $user): bool => $user->isAdmin());
            }
        }

        // Menu sections. Entries name a group by key; the heading and order live here.
        app(NavigationGroups::class)->registerMany([
            NavigationGroup::make('hosting')->label('Hosting')->icon('outline:globe-alt')->sort(10),
            NavigationGroup::make('network')->label('Síť a pošta')->icon('outline:signal')->sort(20),
            NavigationGroup::make('server')->label('Server')->icon('outline:server-stack')->sort(30)
                ->visible(fn (): bool => Gate::allows('admin')),
            NavigationGroup::make('help')->label('Nápověda')->icon('outline:question-mark-circle')->sort(90),
        ]);
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(30)->by(($request->route('hook') ?? '').'|'.$request->ip()));
        RateLimiter::for('ci-deploy', fn (Request $request) => Limit::perMinute(60)->by(($request->route('hook') ?? '').'|'.$request->ip()));
    }
}
