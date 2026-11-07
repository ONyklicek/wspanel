<?php

namespace App\Livewire\Dns;

use App\Wire\Resources\DnsZoneResource;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListDnsZones extends ListPage
{
    protected static ?string $resource = DnsZoneResource::class;

    protected function headerActions(): array
    {
        return [$this->createHeaderAction()?->label('Přidat zónu')];
    }
}
