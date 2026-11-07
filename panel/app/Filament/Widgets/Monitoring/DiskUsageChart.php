<?php

namespace App\Filament\Widgets\Monitoring;

class DiskUsageChart extends MetricsChart
{
    protected ?string $heading = 'Zaplnění disku';

    protected ?string $description = 'Nejplnější oddíl.';

    protected function series(): array
    {
        return ['disk_percent' => ['obsazeno', '#64748b']];
    }
}
