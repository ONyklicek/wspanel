<?php

namespace App\Livewire\Sites;

use App\Livewire\Concerns\TranslatesPageText;
use App\Wire\Resources\SiteResource;
use NyonCode\WirePanels\Resources\Pages\CreatePage;

class CreateSitePage extends CreatePage
{
    use TranslatesPageText;

    protected static ?string $resource = SiteResource::class;

    protected ?string $title = 'Nový web';

    public function submitLabel(): string
    {
        return __('Vytvořit web');
    }
}
