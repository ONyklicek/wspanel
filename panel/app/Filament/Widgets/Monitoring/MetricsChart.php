<?php

namespace App\Filament\Widgets\Monitoring;

use App\Actions\Monitoring\ServerMetrics;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** Line chart over the recorded history, with a range filter. */
abstract class MetricsChart extends ChartWidget
{
    public ?string $filter = '24h';

    protected ?string $pollingInterval = '60s';

    protected ?string $maxHeight = '260px';

    protected static bool $isDiscovered = false;

    /** @return array<string, array{0: string, 1: string}> column => [label, color] */
    abstract protected function series(): array;

    /** Unit of the y axis: percent | bytes | bytes_per_second | plain */
    protected string $unit = 'percent';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        return collect(ServerMetrics::RANGES)->map(fn ($r) => $r[0])->all();
    }

    /** @param  object  $row */
    protected function value(string $column, $row): float
    {
        return round((float) $row->{$column}, 2);
    }

    protected function getData(): array
    {
        $rows = app(ServerMetrics::class)->series($this->filter ?? '24h', array_merge(array_keys($this->series()), $this->extraColumns()));
        $datasets = [];
        foreach ($this->series() as $column => [$label, $color]) {
            $datasets[] = [
                'label' => $label,
                'data' => $rows->map(fn ($r) => $this->value($column, $r))->all(),
                'borderColor' => $color,
                'backgroundColor' => $color.'22',
                'fill' => true,
                'pointRadius' => 0,
                'borderWidth' => 1.5,
                'tension' => 0.25,
            ];
        }

        return ['datasets' => $datasets, 'labels' => $rows->pluck('label')->all()];
    }

    /** @return list<string> columns needed by value() besides the series */
    protected function extraColumns(): array
    {
        return [];
    }

    protected function getOptions(): RawJs
    {
        $format = match ($this->unit) {
            'percent' => "v => v + ' %'",
            'bytes' => "v => { const u = ['B','kB','MB','GB','TB']; let i = 0; while (v >= 1024 && i < 4) { v /= 1024; i++; } return v.toFixed(i ? 1 : 0) + ' ' + u[i]; }",
            'bytes_per_second' => "v => { const u = ['B/s','kB/s','MB/s','GB/s']; let i = 0; while (v >= 1024 && i < 3) { v /= 1024; i++; } return v.toFixed(i ? 1 : 0) + ' ' + u[i]; }",
            default => 'v => v',
        };
        $max = $this->unit === 'percent' ? 'max: 100,' : '';

        return RawJs::make(<<<JS
            {
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } },
                    tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + ({$format})(c.parsed.y) } } },
                scales: {
                    x: { ticks: { maxTicksLimit: 8, autoSkip: true } },
                    y: { beginAtZero: true, {$max} ticks: { callback: {$format} } },
                },
            }
        JS);
    }
}
