<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\ControlSite;
use App\Enums\SiteMode;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Sites\SiteActions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Livewire\Component;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireForms\Components\Display\Placeholder;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Concerns\WithTable;
use NyonCode\WireTable\Data\CollectionDataSource;
use NyonCode\WireTable\Table;

/**
 * Live list of what runs for a site: the app process (worker / node mode),
 * workers and scheduled tasks, straight from systemd via the agent.
 */
class SiteProcesses extends Component
{
    use WithTable;

    public int $site;

    public ?string $agentError = null;

    public function getSite(): Site
    {
        return Site::findOrFail($this->site);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $site = $this->getSite();
        try {
            $this->agentError = null;
            $procs = app(ControlSite::class)->processes($site);
        } catch (AgentException $e) {
            $this->agentError = $e->getMessage();

            return [];
        }

        return collect($procs)->map(fn (array $p) => $p + [
            'id' => $p['unit'],
            'label' => match ($p['kind']) {
                'app' => 'Aplikace ('.$site->runtimeLabel().')',
                'task' => 'Úloha #'.$p['name'],
                default => SiteActions::WORKER_LABELS[$p['name']] ?? $p['name'],
            },
            'state_label' => self::stateLabel($p),
            'since_label' => self::since($p['since'] ?? null),
            'memory_label' => ($p['memory'] ?? 0) > 0 ? Number::fileSize($p['memory'], 1) : '—',
            'boot_label' => ($p['enabled'] ?? '') === 'enabled' ? 'spustí se' : 'nespustí se',
        ])->values()->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->dataSource(new CollectionDataSource($this->rows()))
            ->columns([
                TextColumn::make('label')->label('Proces')->weight('medium')->description(fn ($record) => $record->unit),
                BadgeColumn::make('kind')->label('Druh')->color('gray')
                    ->formatStateUsing(fn ($state) => ['app' => 'aplikace', 'worker' => 'worker', 'task' => 'úloha'][$state] ?? $state),
                BadgeColumn::make('state_label')->label('Stav')->colors([
                    'běží' => 'success', 'naplánováno' => 'success', 'chyba' => 'danger', 'padá a restartuje se' => 'danger',
                    'čeká na deploy / zastaveno' => 'warning', 'zastaveno' => 'gray',
                ]),
                TextColumn::make('since_label')->label('Od'),
                TextColumn::make('memory_label')->label('Paměť'),
                TextColumn::make('restarts')->label('Restartů'),
                TextColumn::make('boot_label')->label('Po startu serveru')->textColor('gray'),
            ])
            ->actions([
                $this->logAction(),
                ActionGroup::make([
                    $this->control('start', 'Spustit', 'outline:play', 'success')
                        ->visible(fn ($record) => $record->kind !== 'task' && $record->active !== 'active'),
                    $this->control('restart', 'Restartovat', 'outline:arrow-path', 'warning')
                        ->visible(fn ($record) => $record->kind !== 'task' && $record->active === 'active'),
                    $this->control('reset', 'Reset po chybě a spustit', 'outline:wrench', 'warning')
                        ->visible(fn ($record) => $record->kind !== 'task' && $record->active === 'failed'),
                    $this->control('stop', 'Zastavit', 'outline:stop', 'danger')
                        ->visible(fn ($record) => $record->kind !== 'task' && $record->active === 'active')
                        ->requiresConfirmation()
                        ->modalDescription('Proces se zastaví do příštího restartu webu nebo serveru. Natrvalo ho vypnete odebráním workeru nebo vypnutím webu.'),
                ]),
            ])
            ->emptyState(
                $this->agentError ? 'Stav se nepodařilo načíst' : 'Žádné samostatné procesy',
                $this->agentError ?? 'Workery (fronty, Horizon, plánovač, Reverb…) přidáte v menu Další → Přidat worker.',
                'outline:cpu-chip',
            )
            ->searchable(false)
            ->paginated(false)
            ->poll('10s');
    }

    private function control(string $verb, string $label, string $icon, string $color): Action
    {
        return Action::make($verb)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->action(function ($record) use ($verb, $label) {
                try {
                    app(ControlSite::class)->control($this->getSite(), $record->kind, $record->name, $verb);
                    Toast::success("{$record->label}: {$label} – hotovo");
                } catch (AgentException $e) {
                    Toast::error("{$record->label}: {$label} selhalo", $e->getMessage());
                }
            });
    }

    private function logAction(): Action
    {
        return Action::make('log')
            ->label('Log')
            ->icon('outline:document-text')
            ->color('gray')
            ->modalHeading(fn ($record) => 'Log: '.$record->label)
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Zavřít')
            ->form(function ($record) {
                try {
                    $out = app(ControlSite::class)->logs($this->getSite(), $record->kind, $record->kind === 'app' ? null : $record->name);
                    $text = (string) ($out['text'] ?? '');
                } catch (AgentException $e) {
                    $text = 'Log se nepodařilo načíst: '.$e->getMessage();
                }

                return [
                    Placeholder::make('log')->hiddenLabel()->html(view('livewire.sites.log-text', ['text' => $text])->render()),
                ];
            })
            ->action(fn () => null);
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
            default => 'zastaveno',
        };
    }

    private static function since(?string $ts): string
    {
        if ($ts === null || $ts === '') {
            return '—';
        }
        try {
            return Carbon::parse((string) preg_replace('/^\w{3} /', '', $ts))->diffForHumans();
        } catch (\Throwable) {
            return $ts;
        }
    }

    public function render(): View
    {
        return view('livewire.sites.site-processes', ['classic' => $this->getSite()->mode === SiteMode::Classic, 'siteModel' => $this->getSite()]);
    }
}
