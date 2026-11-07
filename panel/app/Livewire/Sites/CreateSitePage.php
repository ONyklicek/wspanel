<?php

namespace App\Livewire\Sites;

use App\Wire\Resources\SiteResource;
use NyonCode\WirePanels\Resources\Pages\CreatePage;

class CreateSitePage extends CreatePage
{
    protected static ?string $resource = SiteResource::class;

    protected ?string $title = 'Nový web';
}
