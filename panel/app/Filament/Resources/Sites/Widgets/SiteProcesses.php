<?php

namespace App\Filament\Resources\Sites\Widgets;

use App\Actions\Sites\ControlSite;
use App\Enums\SiteMode;
use App\Models\Site;
use App\Services\Agent\AgentException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * Live list of what runs for a site: the app process (worker / node mode),
 * workers and scheduled tasks, straight from systemd via the agent.
 */
class SiteProcesses extends TableWidget
{
    public ?Site $record = null;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public ?string $agentError = null;

    private const WORKER_LABELS = [
        'app' => 'Aplikace',
        'horizon' => 'Horizon',
        'queue' => 'Fronta (queue:work)',
        'scheduler' => 'Plánovač',
        'reverb' => 'Reverb (WebSockets)',
        'pulse' => 'Pulse – kontroly',
        'pulsework' => 'Pulse – ingest',
        'messenger' => 'Messenger',
    ];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Procesy a workery')
            ->description(fn () => $this->record?->mode === SiteMode::Classic
                ? "Web obsluhuje PHP-FPM {$this->record->php_version} (procesy se spouštějí podle potřeby). Níže jsou samostatně běžící workery a naplánované úlohy."
                : 'Proces aplikace, workery a naplánované úlohy tohoto webu. Stav je živě ze systemd.')
            ->records(fn () => $this->loadProcesses())
            ->poll('10s')
            ->paginated(false)
            ->emptyStateHeading(fn () => $this->agentError ? 'Stav se nepodařilo načíst' : 'Žádné samostatné procesy')
            ->emptyStateDescription(fn () => $this->agentError ?? 'Workery (fronty, Horizon, plánovač, Reverb…) přidáte akcí „Přidat worker“.')
            ->emptyStateIcon(Heroicon::OutlinedCpuChip)
            ->columns([
                TextColumn::make('label')
                    ->label('Proces')
                    ->weight('medium')
                    ->description(fn (array $record) => $record['unit']),
                TextColumn::make('kind')
                    ->label('Druh')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => ['app' => 'aplikace', 'worker' => 'worker', 'task' => 'úloha'][$state] ?? $state),
                TextColumn::make('active')
                    ->label('Stav')
                    ->badge()
                    ->formatStateUsing(fn (array $record) => self::stateLabel($record))
                    ->color(fn (array $record) => match (true) {
                        $record['active'] === 'failed' || ($record['sub'] ?? '') === 'auto-restart' => 'danger',
                        $record['active'] === 'active' => 'success',
                        in_array($record['active'], ['activating', 'deactivating', 'reloading'], true) => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('since')
                    ->label('Od')
                    ->state(fn (array $record) => self::since($record['since'] ?? null)),
                TextColumn::make('memory')
                    ->label('Paměť')
                    ->state(fn (array $record) => ($record['memory'] ?? 0) > 0 ? Number::fileSize($record['memory'], 1) : '—'),
                TextColumn::make('restarts')
                    ->label('Restartů')
                    ->color(fn (array $record) => ($record['restarts'] ?? 0) > 0 ? 'warning' : 'gray'),
                TextColumn::make('enabled')
                    ->label('Po startu serveru')
                    ->formatStateUsing(fn (?string $state) => $state === 'enabled' ? 'spustí se' : 'nespustí se')
                    ->color('gray'),
            ])
            ->recordActions([
                $this->logsAction(),
                ActionGroup::make([
                    $this->control('start', 'Spustit', Heroicon::OutlinedPlay, 'success')
                        ->visible(fn (array $record) => $record['kind'] !== 'task' && $record['active'] !== 'active'),
                    $this->control('restart', 'Restartovat', Heroicon::OutlinedArrowPath, 'warning')
                        ->visible(fn (array $record) => $record['kind'] !== 'task' && $record['active'] === 'active'),
                    $this->control('reset', 'Reset po chybě a spustit', Heroicon::OutlinedWrench, 'warning')
                        ->visible(fn (array $record) => $record['kind'] !== 'task' && $record['active'] === 'failed'),
                    $this->control('stop', 'Zastavit', Heroicon::OutlinedStop, 'danger')
                        ->visible(fn (array $record) => $record['kind'] !== 'task' && $record['active'] === 'active')
                        ->requiresConfirmation()
                        ->modalDescription('Proces se zastaví do příštího restartu webu nebo serveru. Natrvalo ho vypnete odebráním workeru nebo vypnutím webu.'),
                ]),
            ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function loadProcesses(): array
    {
        if ($this->record === null) {
            return [];
        }
        try {
            $this->agentError = null;
            $procs = app(ControlSite::class)->processes($this->record);
        } catch (AgentException $e) {
            $this->agentError = $e->getMessage();

            return [];
        }

        return collect($procs)
            ->map(fn (array $p) => $p + ['label' => match ($p['kind']) {
                'app' => 'Aplikace ('.$this->record->runtimeLabel().')',
                'task' => 'Úloha #'.$p['name'],
                default => self::WORKER_LABELS[$p['name']] ?? $p['name'],
            }])
            ->keyBy('unit')
            ->all();
    }

    private function control(string $verb, string $label, Heroicon $icon, string $color): Action
    {
        return Action::make($verb)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->action(function (array $record) use ($verb, $label) {
                try {
                    app(ControlSite::class)->control($this->record, $record['kind'], $record['name'], $verb);
                    Notification::make()->title("{$record['label']}: {$label} – hotovo")->success()->send();
                } catch (AgentException $e) {
                    Notification::make()->title("{$record['label']}: {$label} selhalo")->body($e->getMessage())->danger()->persistent()->send();
                }
                $this->resetTable();
            });
    }

    private function logsAction(): Action
    {
        return Action::make('logs')
            ->label('Log')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->modalHeading(fn (array $record) => 'Log: '.$record['label'])
            ->modalWidth('6xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Zavřít')
            ->modalContent(function (array $record) {
                try {
                    $out = app(ControlSite::class)->logs($this->record, $record['kind'], $record['kind'] === 'app' ? null : $record['name']);
                    $text = $out['text'] ?? '';
                } catch (AgentException $e) {
                    $text = 'Log se nepodařilo načíst: '.$e->getMessage();
                }

                return view('filament.resources.sites.widgets.log-text', ['text' => $text]);
            });
    }

    /** @param  array<string, mixed>  $p */
    private static function stateLabel(array $p): string
    {
        return match (true) {
            ($p['sub'] ?? '') === 'auto-restart' => 'padá a restartuje se',
            $p['active'] === 'failed' => 'chyba',
            $p['kind'] === 'task' && $p['active'] === 'active' => 'naplánováno',
            $p['active'] === 'active' => 'běží',
            $p['active'] === 'inactive' && ($p['enabled'] ?? '') === 'enabled' && $p['kind'] !== 'task' => 'čeká na deploy / zastaveno',
            $p['active'] === 'inactive' => 'zastaveno',
            default => $p['active'],
        };
    }

    private static function since(?string $ts): string
    {
        if ($ts === null || $ts === '') {
            return '—';
        }
        try {
            return Carbon::parse(preg_replace('/^\w{3} /', '', $ts))->diffForHumans();
        } catch (\Throwable) {
            return $ts;
        }
    }
}
