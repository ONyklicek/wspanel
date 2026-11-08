<?php

namespace App\Wire\Resources;

use App\Actions\Mail\ManageMailHosting;
use App\Livewire\Mail\ListMailDomains;
use App\Livewire\Mail\ViewMailDomain;
use App\Models\MailDomain;
use Illuminate\Support\Facades\Gate;
use NyonCode\WireCore\Core\Resources\Concerns\DescribesRecords;
use NyonCode\WireCore\Core\Resources\Contracts\DescribesResource;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WirePanels\Resources\Contracts\ProvidesResourceTable;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Table;

/** Mail hosting: the domains with mailboxes (for clients too, their own). */
final class MailDomainResource implements DescribesResource, ProvidesNavigation, ProvidesPages, ProvidesResourceTable
{
    use DescribesRecords;

    public static function modelClass(): ?string
    {
        return MailDomain::class;
    }

    public static function key(): string
    {
        return 'mail-domains';
    }

    public static function label(): string
    {
        return 'Poštovní doména';
    }

    public static function pluralLabel(): string
    {
        return 'Schránky';
    }

    public static function pages(): array
    {
        return ['index' => ListMailDomains::class, 'view' => ViewMailDomain::class];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make('Schránky')->icon('outline:inbox-stack')->group('network')->sort(25)
            ->visible(fn (): bool => app(ManageMailHosting::class)->installed());
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(MailDomain::query()->withCount(['mailboxes', 'aliases'])->with('client'))
            ->columns([
                TextColumn::make('domain')->label('Doména')->weight('medium')->searchable()->sortable(),
                TextColumn::make('mailboxes_count')->label('Schránky')->sortable(),
                TextColumn::make('aliases_count')->label('Aliasy'),
                TextColumn::make('client.name')->label('Klient')->placeholder('správce')->visible(fn () => Gate::allows('clients')),
            ])
            ->recordUrl(fn (MailDomain $record) => self::url('view', $record))
            ->emptyState('Zatím žádná poštovní doména', 'Přidejte doménu, pro kterou má server přijímat poštu, a založte v ní schránky.', 'outline:inbox-stack')
            ->defaultSort('domain');
    }
}
