<?php

namespace App\Wire\Resources;

use App\Actions\Firewall\ManageFirewall;
use App\Livewire\Server\ListFirewallRules;
use App\Models\FirewallRule;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use Illuminate\Support\Facades\Cache;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Core\Resources\Concerns\DescribesRecords;
use NyonCode\WireCore\Core\Resources\Contracts\DescribesResource;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WirePanels\Resources\Contracts\ProvidesResourceTable;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Table;

final class FirewallRuleResource implements DescribesResource, ProvidesNavigation, ProvidesPages, ProvidesResourceTable
{
    use DescribesRecords;

    public static function modelClass(): ?string
    {
        return FirewallRule::class;
    }

    public static function key(): string
    {
        return 'firewall';
    }

    public static function label(): string
    {
        return 'Pravidlo';
    }

    public static function pluralLabel(): string
    {
        return 'Firewall';
    }

    public static function pages(): array
    {
        return ['index' => ListFirewallRules::class];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make('Firewall')->icon('outline:shield-check')->group('server')->sort(30);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(FirewallRule::query())
            ->columns([
                TextColumn::make('to')->label('Port')->fontFamily('mono')->weight('medium'),
                BadgeColumn::make('action')->label('Akce')
                    ->formatStateUsing(fn ($state) => ['allow' => 'povolit', 'deny' => 'zakázat', 'reject' => 'odmítnout', 'limit' => 'omezit'][$state] ?? $state)
                    ->colors(['allow' => 'success', 'limit' => 'warning', 'deny' => 'danger', 'reject' => 'danger']),
                TextColumn::make('from')->label('Odkud')->formatStateUsing(fn ($state) => $state === 'Anywhere' ? 'odkudkoli' : $state),
                TextColumn::make('description')->label('Popis')->state(fn (FirewallRule $record) => $record->description ?? $record->comment ?? '—'),
                TextColumn::make('ipv6')->label('IPv6')->formatStateUsing(fn ($state) => $state ? 'ano' : 'ne')->textColor('gray'),
                BadgeColumn::make('managed')->label('Původ')
                    ->formatStateUsing(fn ($state) => $state ? 'panel' : 'systém')
                    ->colors([1 => 'primary', 0 => 'gray']),
            ])
            ->actions([
                Action::make('delete')
                    ->label('Smazat')
                    ->icon('outline:trash')
                    ->color('danger')
                    ->visible(fn (FirewallRule $record) => $record->isDeletable() && ! self::isProtected($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (FirewallRule $record) => "Smazat pravidlo {$record->to} ({$record->action})?")
                    ->action(function (FirewallRule $record) {
                        try {
                            app(ManageFirewall::class)->delete($record);
                            Toast::success('Pravidlo smazáno');
                        } catch (AgentException $e) {
                            Toast::error('Smazání selhalo', $e->getMessage());
                        }
                    }),
            ])
            ->searchable(false)
            ->paginated(false);
    }

    /** Allow rules on SSH/HTTP/HTTPS cannot be removed from the panel (the agent refuses too). */
    private static function isProtected(FirewallRule $rule): bool
    {
        $protected = Cache::get('firewall.status')['protected_ports'] ?? [22, 80, 443];

        return $rule->action === 'allow' && in_array($rule->port, $protected, true);
    }
}
