<?php

namespace App\Providers;

use App\Actions\Sites\ManageSiteMounts;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Dns\DnsPrerequisites;
use App\Support\Features;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use NyonCode\WireCore\Core\Plugin\Hooks\FormConfiguringPayload;
use NyonCode\WireCore\Core\Plugin\Hooks\TableComposingPayload;
use NyonCode\WireCore\Core\Plugin\PluginManager;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationGroup;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationGroups;
use NyonCode\WireCore\Foundation\Enums\Hook;
use NyonCode\WireCore\Foundation\View\PageChrome;
use NyonCode\WireTable\Columns\TextColumn;

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
        // Remembers DNS answers for one request (a form asks several times per render).
        $this->app->scoped(DnsPrerequisites::class);
        $this->app->scoped(ManageSiteMounts::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The web tier receives the panel under an internal host name: every
        // link and redirect is built from APP_URL, the address users open.
        if (filled(config('app.url')) && ! $this->app->runningUnitTests()) {
            URL::forceRootUrl((string) config('app.url'));
            URL::forceScheme('https');
        }

        // The file manager uploads through Livewire: up to the panel's PHP limit (512 MB).
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:524288'], 'livewire.temporary_file_upload.max_upload_time' => 30]);

        // Numbers and sizes in the panel's language (1,5 GB, 12,5 %).
        Number::useLocale((string) config('app.locale'));

        // Administrators manage the server and the panel's accounts; clients
        // only what they own (narrowed by the OwnedByClient scope).
        Gate::define('admin', fn (User $user): bool => $user->isAdmin());
        // The clients module: administrators, and only while it is switched on.
        Gate::define('clients', fn (User $user): bool => $user->isAdmin() && Features::clients());
        // Game servers: while the module is on, for administrators and clients
        // (who see their own, narrowed by the OwnedByClient scope).
        Gate::define('games', fn (User $user): bool => Features::games());
        foreach ((array) config('wire-module-users.permissions') as $abilities) {
            foreach ((array) $abilities as $ability) {
                Gate::define((string) $ability, fn (User $user): bool => $user->isAdmin());
            }
        }

        // Nobody sets another person's password: the account screen offers
        // the password only on one's own account (User::booted() refuses the
        // write anyway). Others reset theirs through "Forgot password".
        app(PluginManager::class)->hook(Hook::FormConfiguring, function (FormConfiguringPayload $payload): FormConfiguringPayload {
            // The page's record, not the form's model: asking the form would configure it again.
            $host = $payload->target?->host;
            $key = is_object($host) && property_exists($host, 'record') ? $host->record : null;
            $record = is_scalar($key) ? User::find($key) : null;
            if ($record instanceof User && $record->exists && $record->id !== auth()->id()) {
                $payload->schema = array_values(array_filter($payload->schema,
                    fn ($component) => ! (is_object($component) && method_exists($component, 'getName') && $component->getName() === 'security')));
            }

            return $payload;
        }, for: 'users');

        // Row buttons in every table (the users module's too) neutral at rest and
        // coloured on hover: a column of red and blue buttons drowns the data.
        // The one action a row is about keeps its colour with ->solid().
        app(PluginManager::class)->hook(Hook::TableComposing, function (TableComposingPayload $payload): TableComposingPayload {
            if (method_exists($payload->table, 'actionsStyle')) {
                $payload->table->actionsStyle('quiet');
            }

            return $payload;
        });

        // Whose account it is: the administrator's or a client's.
        app(PluginManager::class)->hook(Hook::TableComposing, function (TableComposingPayload $payload): TableComposingPayload {
            if (Features::clients()) {
                $payload->columns = [...$payload->columns,
                    TextColumn::make('client.name')->label(__('Účet'))->placeholder(__('správce'))];
            }

            return $payload;
        }, for: 'users');

        // Language switch in the user menu; component round trips keep the language.
        $this->app->make(PageChrome::class)->add('partials.locale-menu', PageChrome::USER_MENU, sort: 90);
        Livewire::addPersistentMiddleware([SetLocale::class]);

        // Menu sections. Entries name a group by key; the heading and order live here.
        app(NavigationGroups::class)->registerMany([
            NavigationGroup::make('hosting')->label(fn (): string => __('Hosting'))->icon('outline:globe-alt')->sort(10),
            NavigationGroup::make('mail')->label(fn (): string => __('Pošta'))->icon('outline:envelope')->sort(20),
            NavigationGroup::make('network')->label(fn (): string => __('Síť'))->icon('outline:signal')->sort(30),
            NavigationGroup::make('server')->label(fn (): string => __('Server'))->icon('outline:server-stack')->sort(40)
                ->visible(fn (): bool => Gate::allows('admin')),
            NavigationGroup::make('security')->label(fn (): string => __('Zabezpečení'))->icon('outline:shield-check')->sort(50)
                ->visible(fn (): bool => Gate::allows('admin')),
            NavigationGroup::make('account')->label(fn (): string => __('Účet'))->icon('outline:user-circle')->sort(80),
            NavigationGroup::make('help')->label(fn (): string => __('Nápověda'))->icon('outline:question-mark-circle')->sort(90),
        ]);
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(30)->by(($request->route('hook') ?? '').'|'.$request->ip()));
        RateLimiter::for('api-v1', fn (Request $request) => Limit::perMinute(120)->by(hash('sha256', (string) $request->bearerToken()).'|'.$request->ip()));
        RateLimiter::for('ci-deploy', fn (Request $request) => Limit::perMinute(60)->by(($request->route('hook') ?? '').'|'.$request->ip()));
    }
}
