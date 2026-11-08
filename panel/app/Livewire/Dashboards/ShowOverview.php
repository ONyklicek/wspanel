<?php

namespace App\Livewire\Dashboards;

use App\Dashboards\OverviewDashboard;
use App\Livewire\Concerns\TranslatesPageText;
use NyonCode\WirePanels\Resources\Pages\DashboardPage;

class ShowOverview extends DashboardPage
{
    use TranslatesPageText;

    protected static ?string $dashboard = OverviewDashboard::class;

    protected ?string $title = 'Přehled';
}
