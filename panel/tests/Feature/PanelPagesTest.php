<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** Every screen renders (agent mocked with plausible answers). */
class PanelPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturnUsing(fn (string $op) => match ($op) {
            'sys.versions' => ['php' => [['version' => '8.4', 'installed' => true]], 'php_default' => '8.4', 'db_engines' => ['mariadb'], 'node' => ['24'], 'worker_php' => '8.5', 'workers' => ['laravel' => ['queue']], 'panel_units' => [], 'dns_local' => true],
            'site.processes' => ['processes' => [['unit' => 'wspanel-web_a_example_com-queue.service', 'kind' => 'worker', 'name' => 'queue', 'active' => 'active', 'sub' => 'running', 'enabled' => 'enabled', 'restarts' => 0, 'memory' => 1048576]]],
            'sys.metrics' => ['cpus' => 4, 'cpu' => ['percent' => 5.5, 'iowait' => 0, 'steal' => 0], 'memory' => ['total' => 8 << 30, 'used' => 2 << 30, 'available' => 6 << 30, 'cache' => 0, 'swap_total' => 0, 'swap_used' => 0], 'net' => ['rx_bps' => 0, 'tx_bps' => 0], 'io' => ['read_bps' => 0, 'write_bps' => 0], 'filesystems' => [['mount' => '/', 'type' => 'ext4', 'total' => 100, 'used' => 10, 'free' => 90, 'inodes_total' => 10, 'inodes_free' => 9]], 'load' => [0.1, 0.1, 0.1], 'uptime_seconds' => 100, 'processes' => 10, 'sites' => [], 'top' => [], 'counters' => []],
            'deploy.history' => ['deploys' => [], 'releases' => ['initial'], 'current' => 'initial', 'running' => false, 'config' => null],
            'sys.site_disk' => ['sizes' => []],
            'dns.records' => ['rrsets' => [['name' => 'www.example.com', 'type' => 'CNAME', 'ttl' => 3600, 'records' => ['example.com.']]]],
            'db.list' => ['databases' => []],
            default => [],
        });
        $this->app->instance(AgentClient::class, $agent);
        $this->actingAs(User::factory()->create());
    }

    private function site(): Site
    {
        return Site::create(['domain' => 'a.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active', 'system_user' => 'web_a_example_com', 'home' => '/srv/sites/a.example.com', 'doc_root' => '/srv/sites/a.example.com/current/public']);
    }

    public function test_overview_renders(): void
    {
        $this->site();
        $this->get('/')->assertOk()->assertSee('Vyžaduje pozornost')->assertSee('a.example.com');
    }

    public function test_database_screens_render(): void
    {
        $db = \App\Models\Database::create(['engine' => 'mariadb', 'name' => 'shop_db', 'site_id' => $this->site()->id]);
        $this->get('/databases')->assertOk()->assertSee('shop_db');
        $this->get("/databases/{$db->id}")->assertOk()->assertSee('Zálohy');
    }

    public function test_dns_screens_render(): void
    {
        $zone = \App\Models\DnsZone::create(['domain' => 'example.com', 'provider' => 'local']);
        $this->get('/dns')->assertOk()->assertSee('example.com');
        $this->get('/dns/create')->assertOk();
        $this->get("/dns/{$zone->id}")->assertOk()->assertSee('www');
    }

    public function test_server_and_network_lists_render(): void
    {
        foreach (['/services', '/firewall', '/ssl', '/tasks'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_monitoring_renders(): void
    {
        $this->get('/monitoring')->assertOk()->assertSee('Vytížení serveru');
    }

    public function test_site_screens_render(): void
    {
        $site = $this->site();
        foreach (['/sites', '/sites/create', "/sites/{$site->id}", "/sites/{$site->id}/deploy"] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get("/sites/{$site->id}")->assertSee('a.example.com')->assertSee('web_a_example_com');
    }
}
