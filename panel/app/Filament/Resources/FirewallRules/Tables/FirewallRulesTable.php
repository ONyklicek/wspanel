<?php

namespace App\Filament\Resources\FirewallRules\Tables;

use App\Actions\Firewall\ManageFirewall;
use App\Models\FirewallRule;
use App\Services\Agent\AgentException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class FirewallRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('port')
            ->columns([
                TextColumn::make('to')->label('Port')->fontFamily('mono')->weight('medium'),
                TextColumn::make('action')
                    ->label('Akce')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'allow' => 'povolit', 'deny' => 'zakázat', 'reject' => 'odmítnout', 'limit' => 'omezit', default => $state,
                    })
                    ->color(fn (string $state) => $state === 'allow' ? 'success' : ($state === 'limit' ? 'warning' : 'danger')),
                TextColumn::make('from')->label('Odkud')->formatStateUsing(fn (string $state) => $state === 'Anywhere' ? 'odkudkoli' : $state),
                TextColumn::make('description')
                    ->label('Popis')
                    ->state(fn (FirewallRule $record) => $record->description ?? $record->comment)
                    ->placeholder('—'),
                IconColumn::make('ipv6')->label('IPv6')->boolean(),
                TextColumn::make('managed')
                    ->label('Původ')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'panel' : 'systém')
                    ->color(fn (bool $state) => $state ? 'primary' : 'gray'),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label('Smazat')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (FirewallRule $record) => $record->isDeletable() && ! self::isProtected($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (FirewallRule $record) => "Smazat pravidlo {$record->to} ({$record->action})?")
                    ->action(function (FirewallRule $record) {
                        try {
                            app(ManageFirewall::class)->delete($record);
                            Notification::make()->title('Pravidlo smazáno')->success()->send();
                        } catch (AgentException $e) {
                            Notification::make()->title('Smazání selhalo')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
            ]);
    }

    /** Allow rules on SSH/HTTP/HTTPS cannot be removed from the panel (the agent refuses too). */
    private static function isProtected(FirewallRule $rule): bool
    {
        $protected = Cache::get('firewall.status')['protected_ports'] ?? [22, 80, 443];

        return $rule->action === 'allow' && in_array($rule->port, $protected, true);
    }
}
