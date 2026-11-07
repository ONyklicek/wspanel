<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Enums\SiteMode;
use App\Enums\SshAccess;
use App\Filament\Resources\Sites\SiteActions;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Site;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SitesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('domain')
            ->recordUrl(fn (Site $record) => SiteResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('domain')
                    ->label('Web')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Site $record) => $record->framework->getLabel().' · '.$record->mode->getLabel()),
                TextColumn::make('state')
                    ->label('Stav')
                    ->badge()
                    ->state(fn (Site $record) => $record->stateLabel())
                    ->color(fn (Site $record) => $record->stateColor())
                    ->tooltip(fn (Site $record) => $record->last_error ?? $record->health_error
                        ?? ($record->health_checked_at ? 'HTTP '.$record->health_status.', ověřeno '.$record->health_checked_at->diffForHumans() : null)),
                TextColumn::make('runtime')
                    ->label('Runtime')
                    ->state(fn (Site $record) => $record->runtimeLabel())
                    ->description(fn (Site $record) => $record->mode === SiteMode::Node && $record->port ? "127.0.0.1:{$record->port}" : null),
                TextColumn::make('workers')
                    ->label('Procesy')
                    ->state(function (Site $record) {
                        $services = $record->services;
                        if ($services->isEmpty()) {
                            return '—';
                        }
                        $running = $services->where('active_state', 'active')->count();

                        return "{$running} / {$services->count()} běží";
                    })
                    ->color(fn (Site $record) => $record->services->contains('active_state', 'failed') ? 'danger' : null)
                    ->tooltip(fn (Site $record) => $record->services->map(fn ($s) => ($s->worker ?? 'aplikace').': '.$s->active_state)->join(', ') ?: null),
                TextColumn::make('ssh_access')
                    ->label('SSH')
                    ->badge()
                    ->color(fn (Site $record) => $record->ssh_access === SshAccess::Off ? 'gray' : 'info'),
                TextColumn::make('system_user')->label('Uživatel')->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('home')->label('Adresář')->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Založeno')->dateTime('j. n. Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('services'))
            ->poll('30s')
            ->recordActions([
                SiteActions::open()->iconButton()->tooltip('Otevřít web'),
                SiteActions::deploy()->iconButton()->tooltip('Deploy'),
                ActionGroup::make(SiteActions::secondary()),
            ]);
    }
}
