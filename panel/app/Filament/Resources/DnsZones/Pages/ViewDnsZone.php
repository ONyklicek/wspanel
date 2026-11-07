<?php

namespace App\Filament\Resources\DnsZones\Pages;

use App\Actions\Dns\ManageDnsZones;
use App\Actions\Mail\ManageMail;
use App\Enums\DnsProvider;
use App\Filament\Resources\DnsZones\DnsZoneResource;
use App\Filament\Resources\DnsZones\RecordForm;
use App\Filament\Resources\DnsZones\Widgets\ZoneRecords;
use App\Models\DnsZone;
use App\Services\Agent\AgentException;
use App\Services\Dns\DnsAdvisor;
use App\Services\Dns\DnsException;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * @property-read DnsZone $record
 */
class ViewDnsZone extends ViewRecord
{
    protected static string $resource = DnsZoneResource::class;

    public function getTitle(): string
    {
        return $this->record->domain;
    }

    protected function getFooterWidgets(): array
    {
        return [ZoneRecords::class];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Zóna')->columns(3)->schema([
                TextEntry::make('provider')->label('Poskytovatel')->badge(),
                TextEntry::make('site.domain')->label('Web')->placeholder('—'),
                TextEntry::make('delegation')
                    ->label('U registrátora nastavte')
                    ->state(fn (DnsZone $record) => $record->provider === DnsProvider::Local
                        ? new HtmlString('nameservery <code>'.e(implode(', ', DnsAdvisor::defaultNameservers())).'</code> (s IP '.e((string) config('panel.public_ipv4')).')')
                        : 'nameservery, které ukazuje Cloudflare u zóny'),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label('Přidat záznam')
                ->icon(Heroicon::OutlinedPlus)
                ->schema(fn () => RecordForm::fields($this->record->domain))
                ->action(function (array $data) {
                    $this->saveRecord(RecordForm::fqdn($data['name'] ?? '', $this->record->domain), $data['type'], (int) $data['ttl'], RecordForm::values($data['records']));
                }),
            Action::make('recommended')
                ->label('Doplnit doporučené')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->modalDescription('Přidá záznamy, které zóna ještě nemá: A/AAAA na tento server, CAA, SPF, DKIM (je-li klíč) a DMARC. Existující záznamy se nemění.')
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        $dkim = rescue(fn () => app(ManageMail::class)->dkim($this->record->domain, 'get'), null, report: false);
                        $added = app(ManageDnsZones::class)->addRecommended($this->record, isset($dkim['name']) ? $dkim : null);
                        Notification::make()->title($added === [] ? 'Nic nechybělo' : 'Přidáno: '.implode(', ', $added))->success()->send();
                    } catch (DnsException|AgentException $e) {
                        Notification::make()->title('Nepodařilo se')->body($e->getMessage())->danger()->persistent()->send();
                    }
                    $this->dispatch('dns-changed');
                }),
            Action::make('check')
                ->label('Kontrola DNS')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->modalHeading(fn () => "Veřejné DNS pro {$this->record->domain}")
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Zavřít')
                ->modalContent(fn () => view('filament.resources.dns-zones.check', [
                    'checks' => DnsAdvisor::check($this->record->domain, $this->record->provider === DnsProvider::Local ? DnsAdvisor::defaultNameservers() : []),
                ])),
            Action::make('delete')
                ->label('Odebrat zónu')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->schema(fn () => $this->record->provider === DnsProvider::Local
                    ? [Toggle::make('remove')->label('Smazat zónu i z DNS serveru (doména přestane fungovat, pokud na server míří)')->default(false)]
                    : [])
                ->modalDescription(fn () => $this->record->provider === DnsProvider::Cloudflare ? 'Zóna se jen odpojí od panelu, na Cloudflare zůstane beze změny.' : null)
                ->action(function (array $data) {
                    try {
                        app(ManageDnsZones::class)->delete($this->record, (bool) ($data['remove'] ?? false));
                        $this->redirect(DnsZoneResource::getUrl('index'));
                    } catch (DnsException $e) {
                        Notification::make()->title('Nepodařilo se')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }

    /** @param  list<string>  $values */
    private function saveRecord(string $name, string $type, int $ttl, array $values): void
    {
        try {
            $this->record->api()->set($this->record->domain, $name, $type, $ttl, $values);
            Notification::make()->title("Uloženo: {$name} {$type}")->success()->send();
        } catch (DnsException $e) {
            Notification::make()->title('Uložení selhalo')->body($e->getMessage())->danger()->persistent()->send();
        }
        $this->dispatch('dns-changed');
    }
}
