<?php

namespace App\Wire\Resources;

use App\Actions\Services\ControlService;
use App\Actions\Sites\ManageWorkers;
use App\Enums\ServiceType;
use App\Livewire\Server\ListServices;
use App\Models\Service;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Toast;
use App\Wire\Actions\Action;
use Illuminate\Support\Facades\Gate;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireCore\Core\Resources\Concerns\DescribesRecords;
use NyonCode\WireCore\Core\Resources\Contracts\DescribesResource;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WireCore\Foundation\Routing\RoutePage;
use NyonCode\WirePanels\Resources\Contracts\ProvidesResourceTable;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Filters\SelectFilter;
use NyonCode\WireTable\Table;

final class ServiceResource implements DescribesResource, ProvidesNavigation, ProvidesPages, ProvidesResourceTable
{
    use DescribesRecords;

    public const STATES = ['active' => 'běží', 'inactive' => 'zastaveno', 'failed' => 'chyba', 'activating' => 'startuje', 'deactivating' => 'zastavuje se', 'reloading' => 'načítá'];

    public static function modelClass(): ?string
    {
        return Service::class;
    }

    public static function key(): string
    {
        return 'services';
    }

    public static function label(): string
    {
        return __('Služba');
    }

    public static function pluralLabel(): string
    {
        return __('Služby');
    }

    public static function pages(): array
    {
        return ['index' => RoutePage::make(ListServices::class)->permission('admin')];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make()->icon('outline:server-stack')->group('server')->sort(20)->visible(fn (): bool => Gate::allows('admin'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            // The panel and the web first, then databases, mail and the rest of
            // the system, the sites' workers last.
            ->query(Service::query()->with('site')->orderByRaw("case type when 'proxy' then 0 when 'web' then 1 when 'php-fpm' then 2 when 'mariadb' then 3 when 'postgresql' then 4 when 'mongodb' then 5 when 'system' then (case when unit like 'wspanel-db-%' or unit like 'postgresql@%' then 6 when unit like 'panel-%' then 2 else 7 end) when 'valkey' then 8 when 'app' then 9 else 10 end")->orderBy('unit'))
            ->columns([
                TextColumn::make('name')->label(__('Služba'))->weight('medium')
                    ->state(fn (Service $record) => $record->label())
                    ->description(fn (Service $record) => $record->isWaitingForDeploy() ? __('spustí ho první deploy webu') : $record->unit),
                BadgeColumn::make('state')->label(__('Stav'))
                    ->state(fn (Service $record) => $record->stateLabel())
                    ->colors([__('běží') => 'success', __('chyba') => 'danger', __('zastaveno') => 'gray', __('startuje') => 'warning', __('zastavuje se') => 'warning', __('načítá') => 'warning', __('čeká na deploy') => 'info'])
                    ->tooltip(fn (Service $record) => $record->sub_state),
                TextColumn::make('boot')->label(__('Po startu serveru'))->textColor('gray')
                    ->state(fn (Service $record) => $record->isWaitingForDeploy() ? __('po prvním deployi') : match ($record->enabled_state) {
                        'enabled', 'enabled-runtime', 'alias' => __('spustí se'),
                        'disabled' => __('nespustí se'),
                        'static', 'indirect', 'generated' => __('podle jiné služby'),
                        'masked' => __('zakázaná'),
                        null, '' => '—',
                        default => $record->enabled_state,
                    }),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Typ'))->options(collect(ServiceType::cases())->mapWithKeys(fn (ServiceType $t) => [$t->value => $t->getLabel()])->all()),
            ])
            ->actions([
                ActionGroup::make([
                    self::control('start', __('Spustit'), 'outline:play', 'success')->visible(fn (Service $record) => ! $record->isRunning()),
                    self::control('restart', __('Restartovat'), 'outline:arrow-path', 'warning')
                        ->requiresConfirmation()
                        ->modalDescription(fn (Service $record) => self::isPanelUnit($record)
                            ? __('Na této službě běží i panel. Během restartu bude panel na pár sekund nedostupný.')
                            : __('Služba se zastaví a znovu spustí. Probíhající požadavky se přeruší.')),
                    self::control('reload', __('Načíst konfiguraci'), 'outline:arrow-path-rounded-square', 'info')
                        ->visible(fn (Service $record) => $record->isRunning() && $record->type->supportsReload()),
                    self::control('reset', __('Reset po chybě'), 'outline:wrench', 'warning')
                        ->visible(fn (Service $record) => $record->isFailed())
                        ->requiresConfirmation()
                        ->modalDescription(__('Vymaže chybový stav (i limit pokusů o start) a službu znovu spustí.')),
                    self::control('stop', __('Zastavit'), 'outline:stop', 'danger')
                        ->visible(fn (Service $record) => $record->isRunning() && ! self::isPanelUnit($record))
                        ->requiresConfirmation(),
                    Action::make('removeWorker')->label(__('Odebrat worker'))->icon('outline:trash')->color('danger')
                        ->visible(fn (Service $record) => $record->type === ServiceType::Worker && $record->site !== null)
                        ->requiresConfirmation()
                        ->modalDescription(__('Worker se zastaví a jeho systemd unita se odstraní.'))
                        ->action(function (Service $record) {
                            try {
                                app(ManageWorkers::class)->delete($record->site, (string) $record->worker);
                                Toast::success(__('Worker odebrán'));
                            } catch (AgentException $e) {
                                Toast::error(__('Odebrání selhalo'), $e->getMessage());
                            }
                        }),
                ]),
            ])
            ->poll('15s')
            ->perPage(50);
    }

    private static function control(string $verb, string $label, string $icon, string $color): Action
    {
        return Action::make($verb)->label($label)->icon($icon)->color($color)
            ->action(function (Service $record) use ($verb, $label) {
                try {
                    app(ControlService::class)->handle($record, $verb);
                    Toast::success("{$record->label()}: {$label} – hotovo");
                } catch (AgentException $e) {
                    Toast::error("{$record->label()}: {$label} selhalo", $e->getMessage());
                }
            });
    }

    private static function isPanelUnit(Service $record): bool
    {
        return in_array($record->unit, app(SystemInfo::class)->panelUnits(), true);
    }
}
