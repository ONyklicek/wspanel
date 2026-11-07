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

        return $client instanceof Client ? $client->name : 'Klient';
    }

    protected function headerActions(): array
    {
        return [
            ClientActions::impersonate(),
            ActionGroup::make(array_values(array_filter(ClientActions::more(), fn ($a) => $a->getName() !== 'impersonate')))
                ->label('Další')->icon('outline:ellipsis-vertical'),
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
                    'override' => 'výjimka pro tohoto klienta',
                    'package' => 'z balíčku',
                    default => 'bez balíčku',
                });
        }

        return Infolist::make()->record($client)->livewireComponent($this)->schema([
            Section::make('client')->label('Klient')->icon('outline:user')->columns(2)->schema([
                TextEntry::make('status')->label('Stav')->state(fn () => $client->isSuspended() ? 'pozastavený od '.$client->suspended_at->format('j. n. Y') : 'aktivní')
                    ->badge()->color($client->isSuspended() ? 'danger' : 'success'),
                TextEntry::make('package')->label('Balíček')->state(fn () => $client->package?->name ?? 'bez balíčku (neomezeno)'),
                TextEntry::make('company')->label('Firma')->state(fn () => $client->company ?: '—'),
                TextEntry::make('contact')->label('Kontakt')->state(fn () => trim(($client->email ?? '').' '.($client->phone ?? '')) ?: '—'),
                TextEntry::make('ssh')->label('SSH / SFTP')->state(fn () => $client->sshLimit()->getLabel()),
                TextEntry::make('notes')->label('Poznámka')->state(fn () => $client->notes ?: '—'),
            ]),
            Section::make('usage')->label('Spotřeba a limity')->icon('outline:chart-pie')->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->schema($limitEntries),
            Section::make('users')->label('Přihlášení do panelu')->icon('outline:key')->schema([
                TextEntry::make('logins')->label('Uživatelé')
                    ->state(fn () => $client->users()->orderBy('email')->get()->map(fn (User $u) => $u->name.' <'.$u->email.'>')->join(', ') ?: 'žádné'),
            ]),
            Section::make('sites')->label('Weby')->icon('outline:globe-alt')->schema([
                TextEntry::make('site_list')->label('Weby klienta')
                    ->state(fn () => $client->sites()->orderBy('domain')->get()->map(fn (Site $s) => $s->domain.' ('.$s->stateLabel().')')->join(', ') ?: 'žádné'),
            ]),
        ]);
    }
}
