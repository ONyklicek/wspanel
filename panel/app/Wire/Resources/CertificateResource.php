<?php

namespace App\Wire\Resources;

use App\Livewire\Network\ListCertificates;
use App\Models\Certificate;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\Toast;
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
        return 'Certifikát';
    }

    public static function pluralLabel(): string
    {
        return 'SSL certifikáty';
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
                TextColumn::make('domain')->label('Doména')->searchable()->sortable()->weight('medium'),
                BadgeColumn::make('status')->label('Stav')
                    ->formatStateUsing(fn ($state) => ['valid' => 'platný', 'expiring' => 'brzy vyprší', 'expired' => 'vypršel', 'missing' => 'chybí'][$state] ?? $state)
                    ->colors(['platný' => 'success', 'brzy vyprší' => 'warning', 'vypršel' => 'danger', 'chybí' => 'danger'])
                    ->tooltip(fn (Certificate $record) => $record->last_error),
                TextColumn::make('mode')->label('Typ')
                    ->formatStateUsing(fn ($state) => ['auto' => "Let's Encrypt", 'internal' => 'interní CA', 'custom' => 'vlastní certifikát'][$state] ?? $state)
                    ->description(fn (Certificate $record) => 'vydal '.$record->issuerLabel()),
                TextColumn::make('not_after')->label('Platí do')->date('j. n. Y')->sortable()
                    ->description(fn (Certificate $record) => $record->not_after?->diffForHumans()),
            ])
            ->actions([
                Action::make('check')
                    ->label('Proč se nevydává?')
                    ->icon('outline:magnifying-glass')
                    ->color('gray')
                    ->visible(fn (Certificate $record) => $record->mode === 'auto' && $record->status !== 'valid')
                    ->action(function (Certificate $record) {
                        try {
                            $r = app(AgentClient::class)->call('ssl.check', ['domain' => $record->domain]);
                        } catch (AgentException $e) {
                            Toast::error('Kontrola selhala', $e->getMessage());

                            return;
                        }
                        $lines = 'DNS: '.(($r['resolves_to'] ?? []) === [] ? 'nic ('.($r['dns_error'] ?? 'nenalezeno').')' : implode(', ', $r['resolves_to']))
                            .($r['dns_points_here'] ? ' ✓ míří na server' : ' ✗ nemíří na server ('.implode(', ', $r['server_addresses']).')')
                            .' · Port 443: '.($r['port_443_open'] ? '✓ otevřený' : '✗ zavřený ve firewallu');
                        $r['dns_points_here'] && $r['port_443_open']
                            ? Toast::success("{$record->domain}: předpoklady splněné", $lines)
                            : Toast::warning("{$record->domain}: něco chybí", $lines.(! $r['dns_points_here'] ? ' · '.$r['note'] : ''));
                    }),
            ])
            ->perPage(25)
            ->defaultSort('domain');
    }
}
