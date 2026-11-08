<?php

namespace App\Livewire\Dashboards;

use App\Dashboards\MonitoringDashboard;
use App\Livewire\Concerns\TranslatesPageText;
use NyonCode\WirePanels\Resources\Pages\DashboardPage;

class ShowMonitoring extends DashboardPage
{
    use TranslatesPageText;

    protected static ?string $dashboard = MonitoringDashboard::class;

    protected ?string $title = 'Vytížení serveru';
}
