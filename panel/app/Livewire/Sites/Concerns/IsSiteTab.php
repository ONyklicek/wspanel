<?php

namespace App\Livewire\Sites\Concerns;

use App\Models\Site;
use App\Wire\Resources\SiteResource;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;

/**
 * A tab of a site's page: headed by the site's domain on every tab (the tab
 * row says which part is open), with the trail Weby › domain › tab.
 */
trait IsSiteTab
{
    /** The tab's name in the trail ("Deploy", "Zálohy"). */
    abstract protected function tabLabel(): string;

    public function getTitle(): ?string
    {
        $site = $this->nativeRecord();

        return $site instanceof Site ? $site->domain : __('Web');
    }

    public function breadcrumbs(): array
    {
        $site = $this->nativeRecord();
        if (! $site instanceof Site) {
            return parent::breadcrumbs();
        }

        return [
            NavigationItem::make(__('Weby'))->url(SiteResource::url('index')),
            NavigationItem::make($site->domain)->url(SiteResource::url('view', $site)),
            NavigationItem::make($this->tabLabel()),
        ];
    }
}
