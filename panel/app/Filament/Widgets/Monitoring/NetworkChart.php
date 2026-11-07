<?php

namespace App\Filament\Widgets\Monitoring;

class NetworkChart extends MetricsChart
{
    protected ?string $heading = 'Síť';

    protected string $unit = 'bytes_per_second';

    protected function series(): array
    {
        return ['net_rx' => ['příchozí', '#0ea5e9'], 'net_tx' => ['odchozí', '#f97316']];
    }
}
