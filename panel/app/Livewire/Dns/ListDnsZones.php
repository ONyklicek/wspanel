<?php

namespace App\Livewire\Dns;

use App\Livewire\Concerns\TranslatesPageText;
use App\Wire\Resources\DnsZoneResource;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListDnsZones extends ListPage
{
    use TranslatesPageText;

    protected static ?string $resource = DnsZoneResource::class;

    protected function headerActions(): array
    {
        return [$this->createHeaderAction()?->label(__('Přidat zónu'))];
    }
}
