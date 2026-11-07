<?php

namespace App\Filament\Resources\DnsZones\Widgets;

use App\Filament\Resources\DnsZones\RecordForm;
use App\Models\DnsZone;
use App\Services\Dns\DnsException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** The zone's records, live from its provider. */
class ZoneRecords extends TableWidget
{
    public ?DnsZone $record = null;

    protected int|string|array $columnSpan = 'full';

    public ?string $providerError = null;

    protected $listeners = ['dns-changed' => '$refresh'];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Záznamy')
            ->records(fn () => $this->load())
            ->paginated(false)
            ->emptyStateHeading(fn () => $this->providerError ? 'Záznamy se nepodařilo načíst' : 'Zóna je prázdná')
            ->emptyStateDescription(fn () => $this->providerError)
            ->columns([
                TextColumn::make('name')->label('Název')->fontFamily('mono')
                    ->formatStateUsing(fn (string $state) => $state === $this->record->domain ? '@' : str_replace('.'.$this->record->domain, '', $state)),
                TextColumn::make('type')->label('Typ')->badge()->color('gray'),
                TextColumn::make('records')->label('Hodnota')->fontFamily('mono')->listWithLineBreaks()->wrap()
                    ->formatStateUsing(fn (string $state) => mb_strimwidth($state, 0, 120, '…')),
                TextColumn::make('ttl')->label('TTL'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Upravit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (array $record) => ! in_array($record['type'], ['SOA'], true))
                    ->fillForm(fn (array $record) => ['name' => $record['name'], 'type' => $record['type'], 'ttl' => $record['ttl'], 'records' => implode("\n", array_map(fn ($v) => $record['type'] === 'TXT' ? str_replace('" "', '', trim($v, '"')) : $v, $record['records']))])
                    ->schema(fn () => RecordForm::fields($this->record->domain, editing: true))
                    ->action(fn (array $record, array $data) => $this->save($record['name'], $record['type'], (int) $data['ttl'], RecordForm::values($data['records']))),
                Action::make('delete')
                    ->label('Smazat')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (array $record) => ! in_array($record['type'], ['SOA'], true))
                    ->requiresConfirmation()
                    ->modalDescription(fn (array $record) => "Smaže všechny hodnoty {$record['type']} pro {$record['name']}.")
                    ->action(fn (array $record) => $this->save($record['name'], $record['type'], (int) $record['ttl'], [])),
            ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function load(): array
    {
        try {
            $this->providerError = null;

            return collect($this->record->api()->rrsets($this->record->domain))
                ->keyBy(fn ($s) => $s['name'].'|'.$s['type'])
                ->all();
        } catch (DnsException $e) {
            $this->providerError = $e->getMessage();

            return [];
        }
    }

    /** @param  list<string>  $values */
    public function save(string $name, string $type, int $ttl, array $values): void
    {
        try {
            $this->record->api()->set($this->record->domain, $name, $type, $ttl, $values);
            Notification::make()->title($values === [] ? "Smazáno: {$name} {$type}" : "Uloženo: {$name} {$type}")->success()->send();
        } catch (DnsException $e) {
            Notification::make()->title('Uložení selhalo')->body($e->getMessage())->danger()->persistent()->send();
        }
        $this->resetTable();
    }
}
