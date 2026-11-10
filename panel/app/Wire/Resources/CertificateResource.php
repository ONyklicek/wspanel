<?php

namespace App\Wire\Resources;

use App\Livewire\Network\ListCertificates;
use App\Models\Certificate;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Actions\Action;
use Illuminate\Support\Facades\Gate;
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

final class CertificateResource implements DescribesResource, ProvidesNavigation, ProvidesPages, ProvidesResourceTable
{
    use DescribesRecords;

    public static function modelClass(): ?string
    {
        return Certificate::class;
    }

    public static function key(): string
    {
        return 'ssl';
    }

    public static function label(): string
    {
        return __('Certifikát');
    }

    public static function pluralLabel(): string
    {
        return __('SSL certifikáty');
    }

    public static function pages(): array
    {
        return ['index' => RoutePage::make(ListCertificates::class)->permission('admin')];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make('SSL')->icon('outline:lock-closed')->group('network')->sort(20)->visible(fn (): bool => Gate::allows('admin'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(Certificate::query())
            ->columns([
                TextColumn::make('domain')->label(__('Doména'))->searchable()->sortable()->weight('medium'),
                BadgeColumn::make('status')->label(__('Stav'))
                    ->formatStateUsing(fn ($state) => ['valid' => __('platný'), 'expiring' => __('brzy vyprší'), 'expired' => __('vypršel'), 'missing' => __('chybí')][$state] ?? $state)
                    ->colors([__('platný') => 'success', __('brzy vyprší') => 'warning', __('vypršel') => 'danger', __('chybí') => 'danger'])
                    ->tooltip(fn (Certificate $record) => $record->last_error),
                TextColumn::make('mode')->label(__('Typ'))
                    ->formatStateUsing(fn ($state) => ['auto' => "Let's Encrypt", 'internal' => __('interní CA'), 'custom' => __('vlastní certifikát')][$state] ?? $state)
                    ->description(fn (Certificate $record) => 'vydal '.$record->issuerLabel()),
                TextColumn::make('not_after')->label(__('Platí do'))->date('j. n. Y')->sortable()
                    ->description(fn (Certificate $record) => $record->not_after?->diffForHumans()),
            ])
            ->actions([
                Action::make('check')
                    ->label(__('Proč se nevydává?'))
                    ->icon('outline:magnifying-glass')
                    ->color('gray')
                    ->visible(fn (Certificate $record) => $record->mode === 'auto' && $record->status !== 'valid')
                    ->action(function (Certificate $record) {
                        try {
                            $r = app(AgentClient::class)->call('ssl.check', ['domain' => $record->domain]);
                        } catch (AgentException $e) {
                            Toast::error(__('Kontrola selhala'), $e->getMessage());

                            return;
                        }
                        $lines = 'DNS: '.(($r['resolves_to'] ?? []) === [] ? 'nic ('.($r['dns_error'] ?? 'nenalezeno').')' : implode(', ', $r['resolves_to']))
                            .($r['dns_points_here'] ? __(' ✓ míří na server') : __(' ✗ nemíří na server (').implode(', ', $r['server_addresses']).')')
                            .' · Port 443: '.($r['port_443_open'] ? __('✓ otevřený') : __('✗ zavřený ve firewallu'));
                        $r['dns_points_here'] && $r['port_443_open']
                            ? Toast::success(__(':domain: předpoklady splněné', ['domain' => $record->domain]), $lines)
                            : Toast::warning(__(':domain: něco chybí', ['domain' => $record->domain]), $lines.(! $r['dns_points_here'] ? ' · '.$r['note'] : ''));
                    }),
            ])
            ->perPage(25)
            ->defaultSort('domain');
    }
}
