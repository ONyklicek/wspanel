<?php

namespace App\Filament\Widgets\Monitoring;

class LoadChart extends MetricsChart
{
    protected ?string $heading = 'Zátěž (load)';

    protected ?string $description = 'Průměrný počet procesů, které chtějí CPU. Nad počtem jader je server přetížený.';

    protected string $unit = 'plain';

    protected function series(): array
    {
        return ['load1' => ['load 1 min', '#10b981']];
    }
}
