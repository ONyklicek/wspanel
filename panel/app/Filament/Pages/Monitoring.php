<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Monitoring\CpuChart;
use App\Filament\Widgets\Monitoring\DiskIoChart;
use App\Filament\Widgets\Monitoring\DiskUsageChart;
use App\Filament\Widgets\Monitoring\FilesystemsTable;
use App\Filament\Widgets\Monitoring\LiveStats;
use App\Filament\Widgets\Monitoring\LoadChart;
use App\Filament\Widgets\Monitoring\MemoryChart;
use App\Filament\Widgets\Monitoring\NetworkChart;
use App\Filament\Widgets\Monitoring\SitesLoadTable;
use App\Filament\Widgets\Monitoring\TopProcessesTable;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Server load: live values, history charts, disks, per-site usage, processes. */
class Monitoring extends Page
{
    protected string $view = 'filament.pages.monitoring';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Vytížení';

    protected static ?string $title = 'Vytížení serveru';

    protected static ?int $navigationSort = 1;

    protected function getHeaderWidgets(): array
    {
        return [LiveStats::class];
    }

    protected function getFooterWidgets(): array
    {
        return [
            CpuChart::class, MemoryChart::class,
            LoadChart::class, NetworkChart::class,
            DiskIoChart::class, DiskUsageChart::class,
            SitesLoadTable::class, FilesystemsTable::class, TopProcessesTable::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return ['md' => 2];
    }
}
