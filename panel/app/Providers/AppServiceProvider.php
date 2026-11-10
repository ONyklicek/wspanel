<?php

namespace App\Providers;

use App\Actions\Sites\ManageSiteMounts;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Dns\DnsPrerequisites;
use App\Support\Features;
use App\Support\PanelMail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Core\Plugin\Hooks\FormConfiguringPayload;
use NyonCode\WireCore\Core\Plugin\Hooks\TableComposingPayload;
use NyonCode\WireCore\Core\Plugin\PluginManager;
use NyonCode\WireCore\Core\Plugin\RenderHook;
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
    /**
     * The same row actions look the same in every table (the modules' too):
     * view, edit, download and delete are icons with their name on hover,
     * delete always last. Other actions keep their text.
     */
    private static function uniformRowActions(object $table): void
    {
        if (! method_exists($table, 'getActions') || ! method_exists($table, 'actions')) {
            return;
        }
        $icons = ['view' => 'outline:eye', 'edit' => 'outline:pencil-square', 'download' => 'outline:arrow-down-tray',
            'delete' => 'outline:trash', 'drop' => 'outline:trash', 'revoke' => 'outline:trash'];
        $last = ['delete', 'drop', 'revoke'];
        $actions = $table->getActions();
        foreach ($actions as $action) {
            if ($action instanceof Action && isset($icons[$action->getName()])) {
                $action->onlyIcon()->icon($icons[$action->getName()]);
                if (rescue(fn () => $action->getTooltip(), null, report: false) === null) {
                    $action->tooltip(fn ($record = null) => $action->getLabel($record));
                }
            }
        }
        usort($actions, fn ($a, $b) => (int) ($a instanceof Action && in_array($a->getName(), $last, true))
            <=> (int) ($b instanceof Action && in_array($b->getName(), $last, true)));
        $table->actions($actions);
    }

    public function boot(): void
    {
        // The web tier receives the panel under an internal host name: every
        // link and redirect is built from APP_URL, the address users open.
        if (filled(config('app.url')) && ! $this->app->runningUnitTests()) {
            URL::forceRootUrl((string) config('app.url'));
            URL::forceScheme('https');
        }

        // The panel's own mail through an external SMTP server, when one is set.
        PanelMail::apply();

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
        // Which games servers can run (game packages): the administrators, while the module is on.
        Gate::define('game-packages', fn (User $user): bool => $user->isAdmin() && Features::games());
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
            self::uniformRowActions($payload->table);

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
        // What runs in the background (long agent jobs), live, on every page.
        // The server's time, live (in the server's time zone).
        $this->app->make(PageChrome::class)->add('partials.server-clock', PageChrome::TOPBAR, sort: -10);
        $this->app->make(PageChrome::class)->add('partials.task-indicator', PageChrome::TOPBAR);
        // And under a record page's heading, what runs for that record (WatchesAgentTasks).
        RenderHook::add('panels.page.header.end', function (): ?View {
            $component = Livewire::current();

            return is_object($component) && method_exists($component, 'subjectTasks')
                ? view('partials.subject-tasks', ['component' => $component]) : null;
        });
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
