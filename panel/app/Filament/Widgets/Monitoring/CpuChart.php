<?php

namespace App\Filament\Widgets\Monitoring;

class CpuChart extends MetricsChart
{
    protected ?string $heading = 'Procesor';

    protected ?string $description = 'Využití všech jader a čekání na disk (iowait).';

    protected function series(): array
    {
        return ['cpu' => ['CPU', '#f59e0b'], 'iowait' => ['iowait', '#ef4444']];
    }
}
