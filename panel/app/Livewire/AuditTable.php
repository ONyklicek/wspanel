<?php

namespace App\Livewire;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\Lang;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Concerns\WithTable;
use NyonCode\WireTable\Data\CollectionDataSource;
use NyonCode\WireTable\Filters\SelectFilter;
use NyonCode\WireTable\Table;

/** The agent's own audit log: everything privileged the panel asked for. */
class AuditTable extends Component
{
    use WithTable;

    public const AREAS = [
        'site.' => 'Weby', 'deploy.' => 'Deploy', 'db.' => 'Databáze', 'service.' => 'Služby', 'worker.' => 'Workery',
        'fw.' => 'Firewall', 'ssh.' => 'SSH', 'cron.' => 'Úlohy', 'env.' => '.env', 'ssl.' => 'SSL', 'mail.' => 'Pošta', 'dns.' => 'DNS', 'panel.' => 'Panel',
    ];

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        try {
            $entries = app(AgentClient::class)->call('audit.tail', ['limit' => 500]);
        } catch (AgentException) {
            return [];
        }

        return collect($entries)->values()->map(fn (array $e, int $i) => [
            'id' => $i,
            'time' => Carbon::parse($e['ts'])->format('j. n. Y H:i:s'),
            'ago' => Carbon::parse($e['ts'])->diffForHumans(),
            'op' => $e['op'],
            'area' => collect(array_keys(self::AREAS))->first(fn ($p) => str_starts_with($e['op'], $p)) ?? 'other',
            'params' => isset($e['params']) ? mb_strimwidth(json_encode($e['params'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 120, '…') : '',
            'result' => $e['ok'] ? 'OK' : 'chyba',
            'error' => $e['error'] ?? null,
            'duration' => $e['duration_ms'].' ms',
            'peer' => match ($e['peer_uid'] ?? null) {
                0 => 'root (timer)', null, -1 => '—', default => 'panel'
            },
        ])->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->dataSource(new CollectionDataSource($this->rows()))
            ->columns([
                TextColumn::make('time')->label(__('Čas'))->description(fn ($r) => $r->ago),
                TextColumn::make('op')->label(__('Operace'))->fontFamily('mono')->weight('medium')->searchable(),
                TextColumn::make('params')->label(__('Parametry'))->fontFamily('mono')->searchable(),
                BadgeColumn::make('result')->label(__('Výsledek'))->colors(['OK' => 'success', 'chyba' => 'danger'])->tooltip(fn ($r) => $r->error),
                TextColumn::make('duration')->label(__('Trvání')),
                TextColumn::make('peer')->label(__('Kdo'))->textColor('gray'),
            ])
            ->filters([SelectFilter::make('area')->label(__('Oblast'))->options(Lang::values(self::AREAS))])
            ->perPage(50)
            ->poll('30s');
    }

    public function render(): View
    {
        return view('livewire.pages.audit-table');
    }
}
