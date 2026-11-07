<?php

namespace App\Livewire\Sites;

use App\Wire\Resources\SiteResource;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListSites extends ListPage
{
    protected static ?string $resource = SiteResource::class;

    protected function headerActions(): array
    {
        return [$this->createHeaderAction()?->label('Nový web')];
    }
}
