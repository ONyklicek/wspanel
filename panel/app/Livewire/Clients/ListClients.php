<?php

namespace App\Livewire\Clients;

use App\Livewire\Concerns\TranslatesPageText;
use App\Wire\Clients\ClientActions;
use App\Wire\Resources\ClientResource;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListClients extends ListPage
{
    use TranslatesPageText;

    protected static ?string $resource = ClientResource::class;

    protected function headerActions(): array
    {
        return [ClientActions::create()];
    }
}
