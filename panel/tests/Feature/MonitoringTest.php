<?php

namespace Tests\Feature;

use App\Actions\Monitoring\ServerMetrics;
use App\Models\ServerMetric;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    /** @param  array<string, int>  $counters */
    private function snapshot(array $counters = [], float $cpu = 12.5): array
    {
        return [
            'cpus' => 4, 'cpu' => ['percent' => $cpu, 'iowait' => 1.0, 'steal' => 0],
            'memory' => ['total' => 8 << 30, 'available' => 6 << 30, 'used' => 2 << 30, 'cache' => 1 << 30, 'swap_total' => 0, 'swap_used' => 0],
            'net' => ['rx_bps' => 1000, 'tx_bps' => 2000], 'io' => ['read_bps' => 0, 'write_bps' => 4096],
            'filesystems' => [['mount' => '/', 'type' => 'ext4', 'total' => 100, 'used' => 91, 'free' => 9, 'inodes_total' => 10, 'inodes_free' => 5]],
            'processes' => 120, 'load' => [0.5, 0.4, 0.3], 'uptime_seconds' => 90061,
            'counters' => $counters + ['cpu_total' => 1000, 'cpu_idle' => 900, 'cpu_iowait' => 10, 'net_rx' => 0, 'net_tx' => 0, 'io_read' => 0, 'io_write' => 0],
            'sites' => [['domain' => 'shop.example.com', 'user' => 'web_shop_example_com', 'cpu' => 55.5, 'rss' => 300 << 20, 'processes' => 7]],
            'top' => [['pid' => 42, 'user' => 'web_shop_example_com', 'name' => 'php-fpm8.4', 'cmd' => 'php-fpm: pool web_shop_example_com', 'cpu' => 50.0, 'rss' => 80 << 20]],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.site_disk')->andReturn(['sizes' => ['shop.example.com' => 5 << 30]])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    public function test_history_uses_averages_since_the_previous_sample(): void
    {
        $this->agent->shouldReceive('call')->with('sys.metrics')->andReturn(
            $this->snapshot(),
            // a minute later: 600 ticks, 150 idle -> 75 % CPU over the minute, though the instant value is 5 %
            $this->snapshot(['cpu_total' => 1600, 'cpu_idle' => 1050, 'cpu_iowait' => 70, 'net_rx' => 60_000], 5.0),
        );
        $metrics = app(ServerMetrics::class);
        $metrics->record();
        $this->travel(60)->seconds();
        $row = $metrics->record();

        $this->assertEqualsWithDelta(75.0, $row->cpu, 0.1);
        $this->assertEqualsWithDelta(10.0, $row->iowait, 0.1);
        $this->assertSame(1000, (int) $row->net_rx);
        $this->assertEqualsWithDelta(91.0, $row->disk_percent, 0.1);
    }

    public function test_old_samples_are_pruned_and_series_are_bucketed(): void
    {
        ServerMetric::create(['recorded_at' => now()->subDays(40), 'cpu' => 1, 'iowait' => 0, 'load1' => 0, 'mem_used' => 0, 'mem_total' => 0, 'swap_used' => 0, 'net_rx' => 0, 'net_tx' => 0, 'io_read' => 0, 'io_write' => 0, 'disk_percent' => 0]);
        foreach ([10, 30] as $i => $cpu) {
            ServerMetric::create(['recorded_at' => now()->subDays(2)->startOfHour()->addMinutes($i), 'cpu' => $cpu, 'iowait' => 0, 'load1' => 0, 'mem_used' => 0, 'mem_total' => 0, 'swap_used' => 0, 'net_rx' => 0, 'net_tx' => 0, 'io_read' => 0, 'io_write' => 0, 'disk_percent' => 0]);
        }
        $this->agent->shouldReceive('call')->with('sys.metrics')->andReturn($this->snapshot());
        app(ServerMetrics::class)->record();

        $this->assertSame(3, ServerMetric::count());
        $series = app(ServerMetrics::class)->series('7d', ['cpu']);
        $this->assertEqualsWithDelta(20.0, $series->first()->cpu, 0.01);
    }

    public function test_page_and_widgets_render(): void
    {
        $this->agent->shouldReceive('call')->with('sys.metrics')->andReturn($this->snapshot());
        Cache::flush();

        $this->get('/monitoring')->assertOk()
            ->assertSee('Vytížení serveru')
            ->assertSee('12,5 %')->assertSee('91 %')->assertSee('1 d 1 h')
            ->assertSee('shop.example.com')->assertSee('55,5 %')->assertSee('5,0 GB')
            ->assertSee('php-fpm8.4');
    }
}
