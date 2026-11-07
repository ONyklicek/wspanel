<?php

namespace App\Livewire\Dashboards;

use App\Dashboards\OverviewDashboard;
use NyonCode\WirePanels\Resources\Pages\DashboardPage;

class ShowOverview extends DashboardPage
{
    protected static ?string $dashboard = OverviewDashboard::class;

    protected ?string $title = 'Přehled';
}
