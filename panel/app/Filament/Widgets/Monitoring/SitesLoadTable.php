<?php

namespace App\Filament\Widgets\Monitoring;

use App\Actions\Monitoring\ServerMetrics;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Site;
use App\Services\Agent\AgentException;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

/** What each site uses right now: CPU, memory, processes, disk. */
class SitesLoadTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '10s';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Vytížení podle webů')
            ->description('Součet všech procesů webu (PHP-FPM, aplikace, workery, úlohy). CPU je v % jednoho jádra; místo na disku se přepočítává každých 15 minut.')
            ->records(function (?string $sortColumn, ?string $sortDirection) {
                try {
                    $metrics = app(ServerMetrics::class);
                    $disk = $metrics->siteDisk();
                    $sites = collect($metrics->now()['sites'] ?? [])
                        ->map(fn (array $s) => $s + ['disk' => $disk[$s['domain']] ?? null]);
                } catch (AgentException) {
                    return [];
                }
                $sortColumn ??= 'cpu';

                return $sites->sortBy($sortColumn, SORT_REGULAR, $sortDirection !== 'asc')->keyBy('domain')->all();
            })
            ->defaultSort('cpu', 'desc')
            ->paginated(false)
            ->recordUrl(function (array $record) {
                $site = Site::query()->where('domain', $record['domain'])->first();

                return $site ? SiteResource::getUrl('view', ['record' => $site]) : null;
            })
            ->columns([
                TextColumn::make('domain')->label('Web')->weight('medium')->sortable(),
                TextColumn::make('cpu')->label('CPU')->sortable()->formatStateUsing(fn ($state) => Number::format($state, 1).' %')
                    ->color(fn ($state) => $state >= 80 ? 'danger' : ($state >= 40 ? 'warning' : null)),
                TextColumn::make('rss')->label('Paměť')->sortable()->formatStateUsing(fn ($state) => $state > 0 ? Number::fileSize($state, 1) : '—'),
                TextColumn::make('processes')->label('Procesů')->sortable(),
                TextColumn::make('disk')->label('Na disku')->sortable()->formatStateUsing(fn ($state) => $state === null ? '…' : Number::fileSize($state, 1)),
            ]);
    }
}
