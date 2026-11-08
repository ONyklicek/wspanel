<?php

namespace App\Livewire\Sites;

use App\Livewire\Sites\Concerns\IsSiteTab;
use App\Models\Site;
use App\Wire\Resources\SiteResource;
use Illuminate\Contracts\View\View;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** The site's files (a tab of the site). */
class SiteFiles extends ViewPage
{
    protected static ?string $resource = SiteResource::class;

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
    }

    use IsSiteTab;

    protected function tabLabel(): string
    {
        return __('Soubory');
    }

    public function infolist(): Infolist
    {
        return Infolist::make()->schema([]);
    }

    public function render(): View
    {
        return view('livewire.sites.files', [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => $this->subNavigation($this->nativeRecord()),
            'site' => $this->nativeRecord(),
        ]);
    }
}
