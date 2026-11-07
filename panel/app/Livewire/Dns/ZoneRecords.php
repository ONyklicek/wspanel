<?php

namespace App\Livewire\Dns;

use App\Models\DnsZone;
use App\Services\Dns\DnsException;
use App\Support\Toast;
use App\Wire\Dns\RecordForm;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Concerns\WithTable;
use NyonCode\WireTable\Data\CollectionDataSource;
use NyonCode\WireTable\Table;

/** The zone's records, live from its provider (PowerDNS through the agent, or Cloudflare). */
class ZoneRecords extends Component
{
    use WithTable;

    public int $zone;

    public ?string $providerError = null;

    public function getZone(): DnsZone
    {
        return DnsZone::findOrFail($this->zone);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $zone = $this->getZone();
        try {
            $this->providerError = null;
            $sets = $zone->api()->rrsets($zone->domain);
        } catch (DnsException $e) {
            $this->providerError = $e->getMessage();

            return [];
        }

        return collect($sets)->map(fn (array $s) => [
            'id' => $s['name'].'|'.$s['type'],
            'name' => $s['name'],
            'short' => $s['name'] === $zone->domain ? '@' : str_replace('.'.$zone->domain, '', $s['name']),
            'type' => $s['type'],
            'ttl' => $s['ttl'],
            'values' => implode("\n", array_map(fn ($v) => mb_strimwidth($v, 0, 140, '…'), $s['records'])),
            'raw' => $s['records'],
        ])->values()->all();
    }

    #[On('dns-changed')]
    public function refreshRecords(): void
    {
        // re-render; the table reads the provider again
    }

    public function table(Table $table): Table
    {
        $domain = $this->getZone()->domain;

        return $table
            ->stackedOnMobile()
            ->dataSource(new CollectionDataSource($this->rows()))
            ->columns([
                TextColumn::make('short')->label('Název')->fontFamily('mono')->searchable()->sortable(),
                BadgeColumn::make('type')->label('Typ')->color('gray')->sortable(),
                TextColumn::make('values')->label('Hodnota')->fontFamily('mono')->wrap(),
                TextColumn::make('ttl')->label('TTL'),
            ])
            ->actions([
                Action::make('edit')
                    ->label('Upravit')
                    ->icon('outline:pencil-square')
                    ->visible(fn ($record) => $record->type !== 'SOA')
                    ->modalHeading(fn ($record) => "Upravit {$record->short} {$record->type}")
                    ->fillFormUsing(fn ($record) => [
                        'name' => $record->short, 'type' => $record->type, 'ttl' => $record->ttl,
                        'records' => implode("\n", array_map(fn ($v) => $record->type === 'TXT' ? str_replace('" "', '', trim($v, '"')) : $v, (array) $record->raw)),
                    ])
                    ->form(RecordForm::fields($domain, editing: true))
                    ->action(fn ($record, array $data) => $this->save($record->name, $record->type, (int) $data['ttl'], RecordForm::values($data['records']))),
                Action::make('delete')
                    ->label('Smazat')
                    ->icon('outline:trash')
                    ->color('danger')
                    ->visible(fn ($record) => $record->type !== 'SOA')
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => "Smazat {$record->short} {$record->type}?")
                    ->modalDescription('Smaže všechny hodnoty tohoto názvu a typu.')
                    ->action(fn ($record) => $this->save($record->name, $record->type, (int) $record->ttl, [])),
            ])
            ->emptyState(
                $this->providerError ? 'Záznamy se nepodařilo načíst' : 'Zóna je prázdná',
                $this->providerError ?? 'Přidejte záznam, nebo doplňte doporučené.',
                'outline:server',
            )
            ->paginated(false)
            ->defaultSort('short');
    }

    /** @param  list<string>  $values */
    public function save(string $name, string $type, int $ttl, array $values): void
    {
        $zone = $this->getZone();
        try {
            $zone->api()->set($zone->domain, $name, $type, $ttl, $values);
            Toast::success($values === [] ? "Smazáno: {$name} {$type}" : "Uloženo: {$name} {$type}");
        } catch (DnsException $e) {
            Toast::error('Uložení selhalo', $e->getMessage());
        }
    }

    public function render(): View
    {
        return view('livewire.dns.zone-records');
    }
}
