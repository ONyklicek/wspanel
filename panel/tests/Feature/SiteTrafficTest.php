<?php

namespace Tests\Feature;

use App\Actions\Monitoring\SiteTraffic;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteHealthCheck;
use App\Models\SiteTrafficHour;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SiteTrafficTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
    }

    private function site(string $domain = 'app.example.com', ?Client $owner = null): Site
    {
        return Site::acrossClients()->create(['domain' => $domain, 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active', 'client_id' => $owner?->id]);
    }

    /** @param  array<string, int>  $paths */
    private function bucket(int $hour, int $requests, array $paths, int $s5xx = 0): array
    {
        return ['hour' => $hour, 'requests' => $requests, 'bytes' => 100 * $requests, 's2xx' => $requests - $s5xx, 's3xx' => 0, 's4xx' => 0, 's5xx' => $s5xx,
            'bots' => 0, 'total_ms' => 50 * $requests, 'paths' => $paths, 'ips' => ['1.2.3.4' => $requests], 'referers' => []];
    }

    public function test_new_lines_of_the_same_hour_add_up(): void
    {
        $site = $this->site();
        $hour = now()->startOfHour()->timestamp;
        $this->agent->shouldReceive('call')->with('site.traffic', ['domain' => 'app.example.com'])->twice()->andReturn(
            ['buckets' => [$this->bucket($hour, 3, ['/' => 2, '/a' => 1])], 'more' => false],
            ['buckets' => [$this->bucket($hour, 2, ['/' => 1, '/b' => 1], 1)], 'more' => false],
        );

        app(SiteTraffic::class)->collect($site);
        app(SiteTraffic::class)->collect($site);

        $row = SiteTrafficHour::sole();
        $this->assertSame(5, $row->requests);
        $this->assertSame(1, $row->s5xx);
        $this->assertSame(['/' => 3, '/a' => 1, '/b' => 1], $row->top['paths']);
    }

    public function test_the_summary_counts_traffic_and_uptime(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->with('site.traffic', Mockery::any())->andReturn(['buckets' => [$this->bucket(now()->startOfHour()->timestamp, 10, ['/' => 10], 2)]]);
        app(SiteTraffic::class)->collect($site);
        foreach ([true, true, true, false] as $i => $ok) {
            SiteHealthCheck::create(['site_id' => $site->id, 'checked_at' => now()->subMinutes($i), 'ok' => $ok, 'status' => $ok ? 200 : 502, 'ms' => 40]);
        }

        $s = app(SiteTraffic::class)->summary($site, 24);

        $this->assertSame(10, $s['requests']);
        $this->assertSame(2, $s['errors']);
        $this->assertSame(50, $s['avg_ms']);
        $this->assertSame(75.0, $s['uptime']);
        $this->assertSame(10, array_sum(array_column($s['series'], 'requests')));
    }

    public function test_a_client_sees_only_its_own_sites_traffic(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $own = $this->site('alice.cz', $alice);
        $other = $this->site('bob.cz', $bob);
        $this->actingAs(User::factory()->forClient($alice)->create());

        $this->get('/sites/'.$own->id.'/traffic')->assertOk()->assertSee('Návštěvnost');
        $this->get('/sites/'.$other->id.'/traffic')->assertNotFound();
    }
}
