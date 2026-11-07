<?php

namespace App\Wire\Resources;

use App\Livewire\Clients\ListClients;
use App\Livewire\Clients\ViewClient;
use App\Models\Client;
use App\Wire\Clients\ClientActions;
use Illuminate\Support\Facades\Gate;
use NyonCode\WireCore\Actions\ActionGroup;
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

/** Hosting clients (administrators only). */
final class ClientResource implements DescribesResource, ProvidesNavigation, ProvidesPages, ProvidesResourceTable
{
    use DescribesRecords;

    public static function modelClass(): ?string
    {
        return Client::class;
    }

    public static function key(): string
    {
        return 'clients';
    }

    public static function label(): string
    {
        return 'Klient';
    }

    public static function pluralLabel(): string
    {
        return 'Klienti';
    }

    public static function pages(): array
    {
        return [
            'index' => RoutePage::make(ListClients::class)->permission('admin'),
            'view' => RoutePage::make(ViewClient::class)->permission('admin'),
        ];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make()->icon('outline:user-group')->group('hosting')->sort(5)
            ->visible(fn (): bool => Gate::allows('admin'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(Client::query()->with('package')->withCount(['sites' => fn ($q) => $q->withoutGlobalScope('client')]))
            ->columns([
                TextColumn::make('name')->label('Klient')->weight('medium')->searchable()->sortable()
                    ->description(fn (Client $record) => $record->company ?: $record->email),
                TextColumn::make('package.name')->label('Balíček')->placeholder('bez balíčku'),
                TextColumn::make('sites_count')->label('Weby')->sortable()
                    ->state(fn (Client $record) => $record->sites_count.' / '.ClientActions::show($record->limit('max_sites'), $record->package !== null || array_key_exists('max_sites', $record->overrides ?? []))),
                BadgeColumn::make('state')->label('Stav')
                    ->state(fn (Client $record) => $record->isSuspended() ? 'pozastavený' : 'aktivní')
                    ->colors(['aktivní' => 'success', 'pozastavený' => 'danger']),
                TextColumn::make('created_at')->label('Od')->date('j. n. Y')->sortable()->toggleable(),
            ])
            ->recordUrl(fn (Client $record) => self::url('view', $record))
            ->actions([ActionGroup::make(ClientActions::more())])
            ->emptyState('Zatím žádný klient', 'Klient vlastní weby, databáze a DNS zóny a přihlašuje se do panelu jen ke svým věcem.', 'outline:user-group')
            ->defaultSort('name');
    }
}
