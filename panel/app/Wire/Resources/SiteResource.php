<?php

namespace App\Wire\Resources;

use App\Actions\Sites\CreateSite;
use App\Enums\SiteMode;
use App\Enums\SiteStatus;
use App\Enums\SshAccess;
use App\Exceptions\LimitReachedException;
use App\Livewire\Sites\CreateSitePage;
use App\Livewire\Sites\ListSites;
use App\Livewire\Sites\SiteBackups;
use App\Livewire\Sites\SiteDeploy;
use App\Livewire\Sites\SiteFiles;
use App\Livewire\Sites\SiteMail;
use App\Livewire\Sites\SiteTrafficPage;
use App\Livewire\Sites\ViewSite;
use App\Models\Client;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Wire\Sites\SiteActions;
use App\Wire\Sites\SiteFields;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireCore\Core\Resources\Concerns\DescribesRecords;
use NyonCode\WireCore\Core\Resources\Contracts\DescribesResource;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WireCore\Foundation\Routing\RoutePage;
use NyonCode\WireCore\Foundation\Schema\Section;
use NyonCode\WireCore\GlobalSearch\Contracts\GloballySearchable;
use NyonCode\WireCore\GlobalSearch\GlobalSearchResult;
use NyonCode\WireForms\Contracts\ProvidesResourceForm;
use NyonCode\WireForms\Forms\Form;
use NyonCode\WirePanels\Resources\Contracts\ProvidesResourceTable;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Table;

final class SiteResource implements DescribesResource, GloballySearchable, ProvidesNavigation, ProvidesPages, ProvidesResourceForm, ProvidesResourceTable
{
    use DescribesRecords;

    public static function modelClass(): ?string
    {
        return Site::class;
    }

    public static function key(): string
    {
        return 'sites';
    }

    public static function label(): string
    {
        return __('Web');
    }

    public static function pluralLabel(): string
    {
        return __('Weby');
    }

    public static function pages(): array
    {
        return [
            'index' => ListSites::class,
            'create' => CreateSitePage::class,
            'view' => RoutePage::make(ViewSite::class)->label(__('Přehled'))->icon('outline:squares-2x2')->sort(10),
            'deploy' => RoutePage::make(SiteDeploy::class)->uri('{record}/deploy')->label(__('Deploy'))->icon('outline:rocket-launch')->sort(20),
            'files' => RoutePage::make(SiteFiles::class)->uri('{record}/files')->label(__('Soubory'))->icon('outline:folder')->sort(30),
            'backups' => RoutePage::make(SiteBackups::class)->uri('{record}/backups')->label(__('Zálohy'))->icon('outline:archive-box')->sort(40),
            'mail' => RoutePage::make(SiteMail::class)->uri('{record}/mail')->label(__('Pošta'))->icon('outline:envelope')->sort(45),
            'traffic' => RoutePage::make(SiteTrafficPage::class)->uri('{record}/traffic')->label(__('Návštěvnost'))->icon('outline:chart-bar-square')->sort(50),
        ];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make()->icon('outline:globe-alt')->group('hosting')->sort(10);
    }

    public static function globallySearchableAttributes(): array
    {
        return ['domain', 'system_user'];
    }

    public static function toGlobalSearchResult(object $record): GlobalSearchResult
    {
        return new GlobalSearchResult(resourceKey: self::key(), recordKey: $record->getKey(), title: $record->domain,
            subtitle: $record->framework->getLabel().' · '.$record->runtimeLabel(), icon: 'outline:globe-alt');
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(Site::query()->with(['services', 'client', 'databases']))
            ->columns([
                TextColumn::make('domain')
                    ->label(__('Web'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Site $record) => $record->framework->getLabel().' · '.$record->mode->getLabel()),
                BadgeColumn::make('state')
                    ->label(__('Stav'))
                    ->state(fn (Site $record) => $record->stateLabel())
                    ->colors(Site::stateColors())
                    ->tooltip(fn (Site $record) => $record->last_error ?? $record->health_error
                        ?? ($record->health_checked_at ? 'HTTP '.$record->health_status.__(', ověřeno ').$record->health_checked_at->diffForHumans() : null)),
                TextColumn::make('runtime')
                    ->label(__('Běží na'))
                    ->state(fn (Site $record) => $record->runtimeLabel())
                    ->description(fn (Site $record) => $record->mode === SiteMode::Node && $record->port ? "127.0.0.1:{$record->port}" : null),
                TextColumn::make('processes')
                    ->label(__('Procesy'))
                    ->state(function (Site $record) {
                        $services = $record->services;
                        if ($services->isEmpty()) {
                            return '—';
                        }

                        $failed = $services->where('active_state', 'failed')->count();

                        return $services->where('active_state', 'active')->count().' / '.$services->count().__(' běží').($failed ? " · {$failed} chyba" : '');
                    }),
                TextColumn::make('databases_list')
                    ->label(__('Databáze'))
                    ->state(fn (Site $record) => $record->databases->pluck('name')->join(', ') ?: '—')
                    ->toggleable(),
                BadgeColumn::make('valkey_state')
                    ->label(__('Valkey'))
                    ->state(fn (Site $record) => $record->valkey === null ? 'ne' : $record->valkey['maxmemory_mb'].' MB')
                    ->colorUsing(fn ($state) => $state === 'ne' ? 'gray' : 'success')
                    ->toggleable(),
                BadgeColumn::make('ssh_access')
                    ->label('SSH')
                    // "Vypnuto" beside "Běží" reads as a stopped site on a phone card, where the header is not shown.
                    ->formatStateUsing(fn ($state) => $state === SshAccess::Off ? 'bez SSH' : ($state instanceof SshAccess ? $state->getLabel() : $state)),
                TextColumn::make('client.name')->label(__('Klient'))->placeholder(__('správce'))->sortable()
                    ->visible(fn () => Gate::allows('clients')),
                TextColumn::make('created_at')->label(__('Založeno'))->date('j. n. Y')->sortable()->toggleable(),
            ])
            ->recordUrl(fn (Site $record) => self::url('view', $record))
            ->actions([
                SiteActions::open()->onlyIcon()->tooltip(__('Otevřít web')),
                SiteActions::deploy()->onlyIcon()->tooltip(__('Deploy')),
                ActionGroup::make(SiteActions::more()),
            ])
            ->emptyState(__('Zatím žádný web'), __('Založte první web tlačítkem Nový web vpravo nahoře.'), 'outline:globe-alt')
            ->poll('30s')
            ->defaultSort('domain');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('domain')->label(__('Doména'))->schema([SiteFields::domain(), SiteFields::tls()]),
                Section::make('runtime')->label(__('Jak web poběží'))->schema(SiteFields::runtime()),
                Section::make('owner')->label(__('Vlastník'))->schema([SiteFields::client()])
                    ->visible(fn () => Gate::allows('clients') && Client::query()->exists()),
            ])
            ->using(function (array $data): Site {
                if (Site::hostTaken($data['domain'])) {
                    throw ValidationException::withMessages(['data.domain' => __('Web s touto doménou už existuje.')]);
                }
                try {
                    $site = app(CreateSite::class)->handle($data);
                } catch (LimitReachedException|AgentException $e) {
                    throw ValidationException::withMessages(['data.domain' => $e->getMessage()]);
                }
                if ($site->status === SiteStatus::Failed) {
                    $error = (string) $site->last_error;
                    $site->delete();
                    throw ValidationException::withMessages(['data.domain' => __('Web se nepodařilo založit: ').$error]);
                }

                return $site;
            })
            ->successMessage(__('Web založen'));
    }
}
