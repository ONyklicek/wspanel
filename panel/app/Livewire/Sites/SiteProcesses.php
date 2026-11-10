<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\ControlSite;
use App\Enums\SiteMode;
use App\Models\Service;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Actions\Action;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Livewire\Component;
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
                'app' => __('Aplikace (').$site->runtimeLabel().')',
                'task' => __('Úloha #').$p['name'],
                'valkey' => __('Valkey (Redis)'),
                default => Service::workerName((string) $p['name']),
            },
            'detail' => str_contains((string) $p['unit'], '@initial.') && $p['active'] !== 'active' ? __('spustí ho první deploy webu') : $p['unit'],
            'state_label' => self::stateLabel($p),
            'since_label' => self::since($p['since'] ?? null),
            'memory_label' => ($p['memory'] ?? 0) > 0 ? Number::fileSize($p['memory'], 1) : '—',
            'boot_label' => str_contains((string) $p['unit'], '@initial.') && $p['active'] !== 'active' ? __('po prvním deployi') : (($p['enabled'] ?? '') === 'enabled' ? __('spustí se') : __('nespustí se')),
        ])->values()->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->dataSource(new CollectionDataSource($this->rows()))
            ->columns([
                TextColumn::make('label')->label(__('Proces'))->weight('medium')->description(fn ($record) => $record->detail),
                BadgeColumn::make('kind')->label(__('Druh'))->color('gray')
                    ->formatStateUsing(fn ($state) => ['app' => __('aplikace'), 'worker' => 'worker', 'task' => __('úloha'), 'valkey' => 'Valkey'][$state] ?? $state)
                    ->toggleable()->visible(false),
                BadgeColumn::make('state_label')->label(__('Stav'))->colors([
                    __('běží') => 'success', __('naplánováno') => 'success', 'chyba' => 'danger', __('padá a restartuje se') => 'danger',
                    __('čeká na deploy / zastaveno') => 'warning', __('čeká na deploy') => 'info', 'zastaveno' => 'gray', 'pozastaveno' => 'warning',
                ]),
                TextColumn::make('since_label')->label(__('Od')),
                TextColumn::make('memory_label')->label(__('Paměť')),
                TextColumn::make('restarts')->label(__('Restartů')),
                TextColumn::make('boot_label')->label(__('Po startu serveru'))->textColor('gray')->toggleable()->visible(false),
            ])
            ->actions([
                $this->control('start', fn ($r) => $r->kind === 'task' ? __('Obnovit plánování') : __('Spustit'), 'outline:play', 'success')
                    ->visible(fn ($record) => ! in_array($record->active, ['active', 'activating', 'reloading'], true)),
                $this->control('restart', __('Restartovat'), 'outline:arrow-path', 'warning')
                    ->visible(fn ($record) => $record->kind !== 'task' && $record->active === 'active'),
                $this->control('reset', __('Reset po chybách a spustit'), 'outline:wrench-screwdriver', 'warning')
                    ->visible(fn ($record) => $record->kind !== 'task' && ($record->active === 'failed' || $record->active === 'activating' || (int) $record->restarts > 0)),
                $this->control('stop', fn ($r) => $r->kind === 'task' ? __('Pozastavit plánování') : __('Zastavit'), 'outline:stop', 'danger')
                    ->visible(fn ($record) => in_array($record->active, ['active', 'activating', 'reloading'], true))
                    ->requiresConfirmation()
                    ->modalDescription(fn ($record) => $record->kind === 'task'
                        ? __('Úloha se nebude spouštět, dokud plánování neobnovíte (nebo do restartu serveru).')
                        : __('Proces se zastaví do příštího restartu webu nebo serveru. Natrvalo ho vypnete odebráním workeru nebo vypnutím webu.')),
                $this->logAction(),
            ])
            ->emptyState(
                $this->agentError ? __('Stav se nepodařilo načíst') : __('Žádné samostatné procesy'),
                $this->agentError ?? __('Workery (fronty, Horizon, plánovač, Reverb…) přidáte v menu Další → Přidat worker.'),
                'outline:cpu-chip',
            )
            ->searchable(false)
            ->paginated(false)
            ->poll('10s');
    }

    private function control(string $verb, string|\Closure $label, string $icon, string $color): Action
    {
        return Action::make($verb)
            ->label($label)
            ->tooltip($label)
            ->onlyIcon()
            ->icon($icon)
            ->color($color)
            ->action(function ($record) use ($verb, $label) {
                $label = $label instanceof \Closure ? $label($record) : $label;
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
            ->label(__('Log'))
            ->icon('outline:document-text')
            ->color('gray')
            ->modalHeading(fn ($record) => 'Log: '.$record->label)
            ->modalWidth('5xl')
            ->modalSubmitActionLabel(__('Zavřít'))
            ->form(function ($record) {
                try {
                    $out = app(ControlSite::class)->logs($this->getSite(), $record->kind, $record->kind === 'app' ? null : $record->name);
                    $text = (string) ($out['text'] ?? '');
                } catch (AgentException $e) {
                    $text = __('Log se nepodařilo načíst: ').$e->getMessage();
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
            ($p['sub'] ?? '') === 'auto-restart' => __('padá a restartuje se'),
            str_contains((string) $p['unit'], '@initial.') && $p['active'] !== 'active' => __('čeká na deploy'),
            $p['active'] === 'failed' => 'chyba',
            $p['kind'] === 'task' && $p['active'] === 'active' => __('naplánováno'),
            $p['active'] === 'active' => __('běží'),
            $p['active'] === 'inactive' && ($p['enabled'] ?? '') === 'enabled' && $p['kind'] !== 'task' => __('čeká na deploy / zastaveno'),
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
