<?php

namespace App\Livewire\Dns;

use App\Wire\Resources\DnsZoneResource;
use NyonCode\WirePanels\Resources\Pages\CreatePage;

class CreateDnsZone extends CreatePage
{
    protected static ?string $resource = DnsZoneResource::class;

    protected ?string $title = 'Přidat DNS zónu';
}
