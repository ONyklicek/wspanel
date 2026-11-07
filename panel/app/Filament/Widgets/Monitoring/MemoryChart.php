<?php

namespace App\Filament\Widgets\Monitoring;

class MemoryChart extends MetricsChart
{
    protected ?string $heading = 'Paměť';

    protected ?string $description = 'Použitá RAM (bez cache, kterou systém kdykoli uvolní) a swap.';

    protected string $unit = 'bytes';

    protected function series(): array
    {
        return ['mem_used' => ['RAM', '#3b82f6'], 'swap_used' => ['swap', '#a855f7'], 'mem_total' => ['celkem', '#94a3b8']];
    }
}
