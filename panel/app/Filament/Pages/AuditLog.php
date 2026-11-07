<?php

namespace App\Filament\Pages;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Everything the privileged agent did, read from its own audit log (the
 * panel cannot write it). Sensitive parameters are redacted by the agent.
 */
class AuditLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.audit-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Audit';

    protected static ?string $title = 'Audit agenta';

    protected static ?int $navigationSort = 9;

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters): array {
                try {
                    $entries = app(AgentClient::class)->call('audit.tail', array_filter([
                        'limit' => 500,
                        'prefix' => $filters['area']['value'] ?? null,
                    ]));
                } catch (AgentException) {
                    return [];
                }

                return collect($entries)->values()->mapWithKeys(fn (array $e, int $i) => [$i => [
                    'time' => Carbon::parse($e['ts']),
                    'op' => $e['op'],
                    'params' => isset($e['params']) ? json_encode($e['params'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
                    'ok' => $e['ok'],
                    'error' => $e['error'] ?? null,
                    'duration' => $e['duration_ms'],
                    'peer' => match ($e['peer_uid'] ?? null) {
                        0 => 'root (timer)', null, -1 => '—', default => 'panel'
                    },
                ]])->all();
            })
            ->paginated([25, 50, 100])
            ->poll('30s')
            ->columns([
                TextColumn::make('time')->label('Čas')->dateTime('j. n. Y H:i:s')->description(fn (array $record) => $record['time']->diffForHumans()),
                TextColumn::make('op')->label('Operace')->fontFamily('mono')->weight('medium'),
                TextColumn::make('params')->label('Parametry')->fontFamily('mono')->limit(90)->tooltip(fn (array $record) => $record['params']),
                TextColumn::make('ok')->label('Výsledek')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'OK' : 'chyba')
                    ->color(fn (bool $state) => $state ? 'success' : 'danger')
                    ->tooltip(fn (array $record) => $record['error']),
                TextColumn::make('duration')->label('Trvání')->suffix(' ms')->alignEnd(),
                TextColumn::make('peer')->label('Kdo')->color('gray'),
            ])
            ->filters([
                SelectFilter::make('area')->label('Oblast')->options([
                    'site.' => 'Weby', 'deploy.' => 'Deploy', 'db.' => 'Databáze', 'service.' => 'Služby',
                    'worker.' => 'Workery', 'fw.' => 'Firewall', 'ssh.' => 'SSH', 'cron.' => 'Úlohy', 'env.' => '.env', 'ssl.' => 'SSL',
                ]),
            ]);
    }
}
