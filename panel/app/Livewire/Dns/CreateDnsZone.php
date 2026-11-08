<?php

namespace App\Livewire\Dns;

use App\Livewire\Concerns\TranslatesPageText;
use App\Wire\Resources\DnsZoneResource;
use NyonCode\WirePanels\Resources\Pages\CreatePage;

class CreateDnsZone extends CreatePage
{
    use TranslatesPageText;

    protected static ?string $resource = DnsZoneResource::class;

    protected ?string $title = 'Přidat DNS zónu';
}
