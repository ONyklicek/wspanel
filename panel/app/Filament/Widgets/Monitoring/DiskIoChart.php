<?php

namespace App\Filament\Widgets\Monitoring;

class DiskIoChart extends MetricsChart
{
    protected ?string $heading = 'Disk – čtení a zápis';

    protected string $unit = 'bytes_per_second';

    protected function series(): array
    {
        return ['io_read' => ['čtení', '#14b8a6'], 'io_write' => ['zápis', '#e11d48']];
    }
}
