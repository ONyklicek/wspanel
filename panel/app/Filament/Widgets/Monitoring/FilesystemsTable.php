<?php

namespace App\Filament\Widgets\Monitoring;

use App\Actions\Monitoring\ServerMetrics;
use App\Services\Agent\AgentException;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

class FilesystemsTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Disky')
            ->records(function () {
                try {
                    return collect(app(ServerMetrics::class)->now()['filesystems'] ?? [])->keyBy('mount')->all();
                } catch (AgentException) {
                    return [];
                }
            })
            ->paginated(false)
            ->columns([
                TextColumn::make('mount')->label('Oddíl')->fontFamily('mono')->description(fn (array $record) => $record['type']),
                TextColumn::make('used_pct')->label('Obsazeno')->badge()
                    ->state(fn (array $record) => $record['total'] > 0 ? round(100 * $record['used'] / $record['total']) : 0)
                    ->formatStateUsing(fn ($state) => "{$state} %")
                    ->color(fn ($state) => LiveStats::level((float) $state, 80, 90)),
                TextColumn::make('used')->label('Použito')->formatStateUsing(fn ($state) => Number::fileSize($state, 1)),
                TextColumn::make('free')->label('Volno')->formatStateUsing(fn ($state) => Number::fileSize($state, 1)),
                TextColumn::make('total')->label('Celkem')->formatStateUsing(fn ($state) => Number::fileSize($state, 1)),
                TextColumn::make('inodes')->label('Inody')
                    ->state(fn (array $record) => $record['inodes_total'] > 0 ? round(100 * (1 - $record['inodes_free'] / $record['inodes_total'])).' %' : '—')
                    ->tooltip('Počet souborů. Když dojdou, nejde zapisovat ani při volném místě.'),
            ]);
    }
}
