<?php

declare(strict_types=1);

namespace App\Livewire\Dashboards;

use App\Dashboards\OverviewDashboard;
use NyonCode\WirePanels\Resources\Pages\DashboardPage;

/**
 * The Livewire component that renders {@see OverviewDashboard}.
 *
 * Two files rather than one because they are two decisions: the dashboard says
 * *what* is on it and the page says how it is mounted — the same split a
 * resource makes with its list and edit pages. Everything the page does comes
 * from `DashboardPage`: the widget grid, each widget's own polling, and the
 * Customise controls if the dashboard ever says `customisable()`.
 *
 * Give it a heading of its own with `protected ?string $title = 'Today';`
 * otherwise it takes the dashboard's label.
 */
class ShowOverview extends DashboardPage
{
    protected static ?string $dashboard = OverviewDashboard::class;
}
