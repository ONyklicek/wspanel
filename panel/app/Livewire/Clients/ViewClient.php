<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Support\Limits;
use App\Wire\Clients\ClientActions;
use App\Wire\Resources\ClientResource;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireCore\Foundation\Schema\Section;
use NyonCode\WireCore\Infolists\Components\TextEntry;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** A client: contact, what it uses against its limits, its sign-ins and sites. */
class ViewClient extends ViewPage
{
    protected static ?string $resource = ClientResource::class;

    public function getTitle(): ?string
    {
        $client = $this->nativeRecord();

        return $client instanceof Client ? $client->name : __('Klient');
    }

    protected function headerActions(): array
    {
        return [
            ClientActions::impersonate(),
            ActionGroup::make(array_values(array_filter(ClientActions::more(), fn ($a) => $a->getName() !== 'impersonate')))
                ->label(__('Další'))->icon('outline:ellipsis-vertical'),
        ];
    }

    public function infolist(): Infolist
    {
        /** @var Client $client */
        $client = $this->nativeRecord();
        $usage = app(Limits::class)->usage($client);

        $limitEntries = [];
        foreach ($usage as $key => $u) {
            $column = Limits::COUNTED[$key][0];
            $limit = $u['limit'] === null ? '∞' : number_format($u['limit'], 0, ',', ' ');
            $full = $u['limit'] !== null && $u['used'] >= $u['limit'];
            $limitEntries[] = TextEntry::make('limit_'.$key)->label($u['label'])
                ->state(fn () => number_format($u['used'], 0, ',', ' ').' / '.$limit.($u['unit'] ? ' '.$u['unit'] : ''))
                ->badge()->color($full ? 'danger' : ($u['limit'] !== null && $u['used'] >= 0.8 * $u['limit'] ? 'warning' : 'gray'))
                ->helperText(match ($client->limitSource($column)) {
                    'override' => __('výjimka pro tohoto klienta'),
                    'package' => __('z balíčku'),
                    default => __('bez balíčku'),
                });
        }

        return Infolist::make()->record($client)->livewireComponent($this)->schema([
            Section::make('client')->label(__('Klient'))->icon('outline:user')->columns(2)->schema([
                TextEntry::make('status')->label(__('Stav'))->state(fn () => $client->isSuspended() ? __('pozastavený od ').$client->suspended_at->format('j. n. Y') : __('aktivní'))
                    ->badge()->color($client->isSuspended() ? 'danger' : 'success'),
                TextEntry::make('package')->label(__('Balíček'))->state(fn () => $client->package?->name ?? __('bez balíčku (neomezeno)')),
                TextEntry::make('company')->label(__('Firma'))->state(fn () => $client->company ?: '—'),
                TextEntry::make('contact')->label(__('Kontakt'))->state(fn () => trim(($client->email ?? '').' '.($client->phone ?? '')) ?: '—'),
                TextEntry::make('disk')->label(__('Limit disku'))->state(fn () => match ($client->disk_enforced) {
                    true => __('vynucuje jádro (kvóta skupiny ').$client->linux_group.')',
                    false => __('jen měří panel (souborový systém nemá kvóty)'),
                    default => __('zatím nenastaveno na serveru'),
                }),
                TextEntry::make('ssh')->label('SSH / SFTP')->state(fn () => $client->sshLimit()->getLabel()),
                TextEntry::make('notes')->label(__('Poznámka'))->state(fn () => $client->notes ?: '—'),
            ]),
            Section::make('usage')->label(__('Spotřeba a limity'))->icon('outline:chart-pie')->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->schema($limitEntries),
            Section::make('users')->label(__('Přihlášení do panelu'))->icon('outline:key')->schema([
                TextEntry::make('logins')->label(__('Uživatelé'))
                    ->state(fn () => $client->users()->orderBy('email')->get()->map(fn (User $u) => $u->name.' <'.$u->email.'>')->join(', ') ?: __('žádné')),
            ]),
            Section::make('sites')->label(__('Weby'))->icon('outline:globe-alt')->schema([
                TextEntry::make('site_list')->label(__('Weby klienta'))
                    ->state(fn () => $client->sites()->orderBy('domain')->get()->map(fn (Site $s) => $s->domain.' ('.$s->stateLabel().')')->join(', ') ?: __('žádné')),
            ]),
        ]);
    }
}
