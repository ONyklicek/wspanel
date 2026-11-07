<?php

namespace App\Livewire\Dashboards;

use App\Dashboards\MonitoringDashboard;
use NyonCode\WirePanels\Resources\Pages\DashboardPage;

class ShowMonitoring extends DashboardPage
{
    protected static ?string $dashboard = MonitoringDashboard::class;

    protected ?string $title = 'Vytížení serveru';
}
