<?php

namespace App\Filament\Widgets\Monitoring;

use App\Actions\Monitoring\ServerMetrics;
use App\Services\Agent\AgentException;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

class TopProcessesTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '10s';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Nejnáročnější procesy')
            ->description('Podle CPU (v % jednoho jádra) a paměti, měřeno teď.')
            ->records(function () {
                try {
                    return collect(app(ServerMetrics::class)->now()['top'] ?? [])->keyBy('pid')->all();
                } catch (AgentException) {
                    return [];
                }
            })
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Proces')->weight('medium')
                    ->description(fn (array $record) => mb_strimwidth((string) $record['cmd'], 0, 90, '…')),
                TextColumn::make('user')->label('Uživatel')->fontFamily('mono'),
                TextColumn::make('cpu')->label('CPU')->formatStateUsing(fn ($state) => Number::format($state, 1).' %'),
                TextColumn::make('rss')->label('Paměť')->formatStateUsing(fn ($state) => Number::fileSize($state, 1)),
                TextColumn::make('pid')->label('PID')->color('gray'),
            ]);
    }
}
