<?php

namespace App\Livewire\Sites;

use App\Models\Site;
use App\Wire\Resources\SiteResource;
use Illuminate\Contracts\View\View;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** The site's whole backups (a tab of the site). */
class SiteBackups extends ViewPage
{
    protected static ?string $resource = SiteResource::class;

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
    }

    public function getTitle(): ?string
    {
        $site = $this->nativeRecord();

        return 'Zálohy · '.($site instanceof Site ? $site->domain : 'web');
    }

    public function infolist(): Infolist
    {
        return Infolist::make()->schema([]);
    }

    public function render(): View
    {
        return view('livewire.sites.backups', [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => $this->subNavigation($this->nativeRecord()),
            'site' => $this->nativeRecord(),
        ]);
    }
}
