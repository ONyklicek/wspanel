<?php

namespace App\Wire\Resources;

use App\Actions\Mail\ManageMailHosting;
use App\Livewire\Mail\ListMailDomains;
use App\Livewire\Mail\ViewMailDomain;
use App\Models\MailDomain;
use App\Services\Dns\DnsPrerequisites;
use Illuminate\Support\Facades\Gate;
use NyonCode\WireCore\Core\Resources\Concerns\DescribesRecords;
use NyonCode\WireCore\Core\Resources\Contracts\DescribesResource;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WirePanels\Resources\Contracts\ProvidesResourceTable;
use NyonCode\WireTable\Columns\BadgeColumn;
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
        return __('Poštovní doména');
    }

    public static function pluralLabel(): string
    {
        return __('Poštovní domény');
    }

    public static function pages(): array
    {
        return ['index' => ListMailDomains::class, 'view' => ViewMailDomain::class];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make(__('Domény a schránky'))->icon('outline:inbox-stack')->group('mail')->sort(10)
            ->visible(fn (): bool => app(ManageMailHosting::class)->installed());
    }

    /**
     * MX and SPF of the domain, as the mailbox form checks them (kept a few
     * minutes: the list and the domain's page share the answer).
     */
    private static function dnsState(MailDomain $domain): string
    {
        $checks = DnsPrerequisites::mailboxesCached($domain->domain);
        if (collect($checks)->every(fn ($c) => $c['ok'] === null)) {
            return __('neověřeno');
        }

        return DnsPrerequisites::failing($checks) === [] ? __('v pořádku') : __('chybí');
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(MailDomain::query()->withCount(['mailboxes', 'aliases'])->with('client'))
            ->columns([
                TextColumn::make('domain')->label(__('Doména'))->weight('medium')->searchable()->sortable(),
                TextColumn::make('mailboxes_count')->label(__('Schránky'))->sortable(),
                TextColumn::make('aliases_count')->label(__('Aliasy')),
                BadgeColumn::make('dns')->label(__('DNS pro poštu'))
                    ->state(fn (MailDomain $record) => self::dnsState($record))
                    ->colors([__('v pořádku') => 'success', __('chybí') => 'warning', __('neověřeno') => 'gray'])
                    ->tooltip(fn (MailDomain $record) => collect(DnsPrerequisites::failing(DnsPrerequisites::mailboxesCached($record->domain)))->map(fn ($c) => $c['label'].': '.$c['detail'])->implode("\n") ?: null),
                TextColumn::make('client.name')->label(__('Klient'))->placeholder(__('správce'))->visible(fn () => Gate::allows('clients')),
            ])
            ->recordUrl(fn (MailDomain $record) => self::url('view', $record))
            ->emptyState(__('Zatím žádná poštovní doména'), __('Přidejte doménu, pro kterou má server přijímat poštu, a založte v ní schránky.'), 'outline:inbox-stack')
            ->defaultSort('domain');
    }
}
