<?php

namespace App\Filament\Resources\Certificates\Tables;

use App\Models\Certificate;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CertificatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('not_after')
            ->columns([
                TextColumn::make('domain')->label('Doména')->searchable()->weight('medium'),
                TextColumn::make('status')
                    ->label('Stav')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ['valid' => 'platný', 'expiring' => 'brzy vyprší', 'expired' => 'vypršel', 'missing' => 'chybí'][$state] ?? $state)
                    ->color(fn (string $state) => ['valid' => 'success', 'expiring' => 'warning'][$state] ?? 'danger')
                    ->tooltip(fn (Certificate $record) => $record->last_error),
                TextColumn::make('mode')->label('Typ')->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state) => ['auto' => "Let's Encrypt", 'internal' => 'interní CA', 'custom' => 'vlastní'][$state] ?? $state),
                TextColumn::make('issuer')->label('Vydavatel')->state(fn (Certificate $record) => $record->issuerLabel()),
                TextColumn::make('not_after')->label('Platí do')->dateTime('j. n. Y')->sortable()
                    ->description(fn (Certificate $record) => $record->not_after?->diffForHumans()),
                TextColumn::make('last_error')->label('Poslední chyba')->limit(60)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('check')
                    ->label('Proč se nevydává?')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->color('gray')
                    ->visible(fn (Certificate $record) => $record->mode === 'auto' && $record->status !== 'valid')
                    ->action(function (Certificate $record) {
                        try {
                            $r = app(AgentClient::class)->call('ssl.check', ['domain' => $record->domain]);
                        } catch (AgentException $e) {
                            Notification::make()->title('Kontrola selhala')->body($e->getMessage())->danger()->send();

                            return;
                        }
                        $ok = $r['dns_points_here'] && $r['port_443_open'];
                        $lines = [
                            'DNS: '.(($r['resolves_to'] ?? []) === [] ? 'nic ('.($r['dns_error'] ?? 'nenalezeno').')' : implode(', ', $r['resolves_to']))
                                .($r['dns_points_here'] ? ' ✓ míří na server' : ' ✗ neodpovídá adresám serveru ('.implode(', ', $r['server_addresses']).')'),
                            'Port 443: '.($r['port_443_open'] ? '✓ otevřený' : '✗ zavřený ve firewallu'),
                        ];
                        if (! $r['dns_points_here']) {
                            $lines[] = $r['note'];
                        }
                        Notification::make()
                            ->title($ok ? "{$record->domain}: předpoklady splněné" : "{$record->domain}: něco chybí")
                            ->body(implode("\n", $lines))
                            ->color($ok ? 'success' : 'warning')
                            ->icon($ok ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                            ->persistent()
                            ->send();
                    }),
            ]);
    }
}
