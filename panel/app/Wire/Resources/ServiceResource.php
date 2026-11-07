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
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireCore\Core\Resources\Concerns\DescribesRecords;
use NyonCode\WireCore\Core\Resources\Contracts\DescribesResource;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
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
        return 'Služba';
    }

    public static function pluralLabel(): string
    {
        return 'Služby';
    }

    public static function pages(): array
    {
        return ['index' => ListServices::class];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make()->icon('outline:server-stack')->group('server')->sort(20);
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(Service::query()->with('site'))
            ->columns([
                TextColumn::make('name')->label('Služba')->weight('medium')
                    ->state(fn (Service $record) => $record->label())
                    ->description(fn (Service $record) => $record->unit),
                BadgeColumn::make('state')->label('Stav')
                    ->state(fn (Service $record) => self::STATES[$record->active_state] ?? $record->active_state)
                    ->colors(['běží' => 'success', 'chyba' => 'danger', 'zastaveno' => 'gray', 'startuje' => 'warning', 'zastavuje se' => 'warning', 'načítá' => 'warning'])
                    ->tooltip(fn (Service $record) => $record->sub_state),
                TextColumn::make('boot')->label('Po startu serveru')->textColor('gray')
                    ->state(fn (Service $record) => $record->enabled_state === 'enabled' ? 'spustí se' : ($record->enabled_state ?? '—')),
            ])
            ->filters([
                SelectFilter::make('type')->label('Typ')->options(collect(ServiceType::cases())->mapWithKeys(fn (ServiceType $t) => [$t->value => $t->getLabel()])->all()),
            ])
            ->actions([
                ActionGroup::make([
                    self::control('start', 'Spustit', 'outline:play', 'success')->visible(fn (Service $record) => ! $record->isRunning()),
                    self::control('restart', 'Restartovat', 'outline:arrow-path', 'warning')
                        ->requiresConfirmation()
                        ->modalDescription(fn (Service $record) => self::isPanelUnit($record)
                            ? 'Na této službě běží i panel. Během restartu bude panel na pár sekund nedostupný.'
                            : 'Služba se zastaví a znovu spustí. Probíhající požadavky se přeruší.'),
                    self::control('reload', 'Načíst konfiguraci', 'outline:arrow-path-rounded-square', 'info')
                        ->visible(fn (Service $record) => $record->isRunning() && $record->type->supportsReload()),
                    self::control('reset', 'Reset po chybě', 'outline:wrench', 'warning')
                        ->visible(fn (Service $record) => $record->isFailed())
                        ->requiresConfirmation()
                        ->modalDescription('Vymaže chybový stav (i limit pokusů o start) a službu znovu spustí.'),
                    self::control('stop', 'Zastavit', 'outline:stop', 'danger')
                        ->visible(fn (Service $record) => $record->isRunning() && ! self::isPanelUnit($record))
                        ->requiresConfirmation(),
                    Action::make('removeWorker')->label('Odebrat worker')->icon('outline:trash')->color('danger')
                        ->visible(fn (Service $record) => $record->type === ServiceType::Worker && $record->site !== null)
                        ->requiresConfirmation()
                        ->modalDescription('Worker se zastaví a jeho systemd unita se odstraní.')
                        ->action(function (Service $record) {
                            try {
                                app(ManageWorkers::class)->delete($record->site, (string) $record->worker);
                                Toast::success('Worker odebrán');
                            } catch (AgentException $e) {
                                Toast::error('Odebrání selhalo', $e->getMessage());
                            }
                        }),
                ]),
            ])
            ->poll('15s')
            ->perPage(25)
            ->defaultSort('type');
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
