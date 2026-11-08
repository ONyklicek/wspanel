<?php

namespace App\Livewire\Sites;

use App\Actions\Monitoring\SiteTraffic;
use App\Models\Site;
use App\Wire\Resources\SiteResource;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** The site's traffic (from the edge's access log) and uptime (a tab of the site). */
class SiteTrafficPage extends ViewPage
{
    protected static ?string $resource = SiteResource::class;

    public const RANGES = ['24h' => 24, '7d' => 168, '30d' => 720];

    #[Url]
    public string $range = '24h';

    public function getTitle(): ?string
    {
        $site = $this->nativeRecord();

        return __('Návštěvnost · ').($site instanceof Site ? $site->domain : 'web');
    }

    public function infolist(): Infolist
    {
        return Infolist::make()->schema([]);
    }

    public function setRange(string $range): void
    {
        $this->range = array_key_exists($range, self::RANGES) ? $range : '24h';
    }

    public function render(): View
    {
        /** @var Site $site */
        $site = $this->nativeRecord();
        $range = array_key_exists($this->range, self::RANGES) ? $this->range : '24h';

        return view('livewire.sites.traffic', [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => $this->subNavigation($site),
            'site' => $site,
            'range' => $range,
            'stats' => app(SiteTraffic::class)->summary($site, self::RANGES[$range]),
        ]);
    }
}
