<?php

namespace App\Wire\Resources;

use App\Actions\Firewall\ManageFirewall;
use App\Livewire\Server\ListFirewallRules;
use App\Models\FirewallRule;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Core\Resources\Concerns\DescribesRecords;
use NyonCode\WireCore\Core\Resources\Contracts\DescribesResource;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WireCore\Foundation\Routing\RoutePage;
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
        return __('Pravidlo');
    }

    public static function pluralLabel(): string
    {
        return 'Firewall';
    }

    public static function pages(): array
    {
        return ['index' => RoutePage::make(ListFirewallRules::class)->permission('admin')];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make('Firewall')->icon('outline:shield-check')->group('security')->sort(10)->visible(fn (): bool => Gate::allows('admin'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(FirewallRule::query())
            ->columns([
                TextColumn::make('to')->label(__('Port'))->fontFamily('mono')->weight('medium')
                    ->description(fn (FirewallRule $record) => $record->description ?? $record->comment),
                BadgeColumn::make('action')->label(__('Pravidlo'))
                    ->formatStateUsing(fn ($state) => ['allow' => 'povolit', 'deny' => __('zakázat'), 'reject' => __('odmítnout'), 'limit' => 'omezit'][$state] ?? $state)
                    ->colors(['povolit' => 'success', 'omezit' => 'warning', __('zakázat') => 'danger', __('odmítnout') => 'danger']),
                TextColumn::make('from')->label(__('Odkud'))->formatStateUsing(fn ($state) => $state === 'Anywhere' ? 'odkudkoli' : $state),
                BadgeColumn::make('managed')->label(__('Původ'))
                    ->formatStateUsing(fn ($state) => $state ? 'panel' : __('systém'))
                    ->colors(['panel' => 'primary', __('systém') => 'gray']),
            ])
            ->actions([
                Action::make('delete')
                    ->label(__('Smazat'))
                    ->icon('outline:trash')
                    ->color('danger')
                    ->onlyIcon()
                    ->tooltip(__('Smazat pravidlo'))
                    ->visible(fn (FirewallRule $record) => $record->isDeletable() && ! self::isProtected($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (FirewallRule $record) => "Smazat pravidlo {$record->to} ({$record->action})?")
                    ->action(function (FirewallRule $record) {
                        try {
                            app(ManageFirewall::class)->delete($record);
                            Toast::success(__('Pravidlo smazáno'));
                        } catch (AgentException $e) {
                            Toast::error(__('Smazání selhalo'), $e->getMessage());
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
