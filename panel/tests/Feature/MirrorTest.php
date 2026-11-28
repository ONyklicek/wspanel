<?php

namespace Tests\Feature;

use App\Actions\Sites\CreateSite;
use App\Enums\DnsProvider;
use App\Livewire\Pages\MirrorPage;
use App\Models\Client;
use App\Models\DnsZone;
use App\Models\GameServer;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Notify\Alerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class MirrorTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    /** @param  array<string, mixed>  $extra */
    private function state(string $role, array $extra = []): void
    {
        $this->agent->shouldReceive('call')->with('mirror.status')->andReturn(array_replace_recursive([
            'role' => $role, 'node_id' => 'node-aaaaaaaaaaaa', 'name' => 'srv1', 'epoch' => 3, 'port' => 7443,
            'address' => '192.168.1.10', 'lan_address' => '192.168.1.10', 'public_ipv4' => '', 'witness' => ['192.168.1.1'],
            'auto_failover' => true, 'failover_after' => 60, 'sync_every' => 60, 'since' => now()->subHour()->toIso8601String(),
            'peer' => $role === '' ? null : ['node_id' => 'node-bbbbbbbbbbbb', 'name' => 'win', 'address' => '192.168.1.20', 'port' => 7443],
            'version' => '1.11.0',
            'status' => ['peer_up' => true, 'peer' => ['role' => $role === 'standby' ? 'primary' : 'standby', 'version' => '1.11.0', 'sites' => 4], 'layers' => [], 'events' => [], 'witness' => ['192.168.1.1' => true]],
        ], $extra))->byDefault();
    }

    public function test_a_server_outside_a_mirror_offers_both_roles(): void
    {
        $this->state('');

        $this->get('/mirror')->assertOk()->assertSee('Jak zrcadlo funguje')->assertSee('mimo zrcadlo');
    }

    public function test_setting_up_the_primary_shows_the_pairing_code(): void
    {
        $this->state('');
        $this->agent->shouldReceive('call')->once()
            ->with('mirror.init', ['address' => '192.168.1.10', 'peer_address' => '192.168.1.20', 'port' => 7443])
            ->andReturn(['code' => 'WSPM1-ABC-DEF', 'expires' => now()->addHour()->toIso8601String()]);

        $this->pageAction(Livewire::test(MirrorPage::class), 'init', ['address' => '192.168.1.10', 'peer_address' => '192.168.1.20'])
            ->assertHasNoErrors()->assertNotified('Párovací kód');
    }

    public function test_joining_needs_a_well_formed_code(): void
    {
        $this->state('');
        $this->agent->shouldNotReceive('call')->with('mirror.join', Mockery::any());

        $this->pageAction(Livewire::test(MirrorPage::class), 'join', ['primary' => '192.168.1.10', 'code' => 'nonsense', 'address' => '192.168.1.20'])
            ->assertHasErrors();
    }

    public function test_promotion_needs_the_node_id_typed(): void
    {
        $this->state('standby');
        $this->agent->shouldNotReceive('call')->with('mirror.promote', Mockery::any());

        $this->pageAction(Livewire::test(MirrorPage::class), 'promote', ['confirm' => 'node-bbbbbbbbbbbb'])->assertHasErrors();
    }

    public function test_promotion_runs_in_the_background(): void
    {
        $this->state('standby');
        $this->agent->shouldReceive('call')->once()->with('mirror.promote', ['confirm' => 'node-aaaaaaaaaaaa'])->andReturn(['job_id' => 'abcdef0123456789']);
        $this->agent->shouldReceive('call')->with('job.status', Mockery::any())->andReturn(['status' => 'running']);

        $this->pageAction(Livewire::test(MirrorPage::class), 'promote', ['confirm' => 'node-aaaaaaaaaaaa'])
            ->assertHasNoErrors()->assertDispatched('agent-task-started');
    }

    public function test_the_standby_shows_a_down_primary(): void
    {
        $this->state('standby', ['status' => ['peer_up' => false, 'peer_error' => 'connection refused', 'primary_down_since' => now()->subSeconds(30)->toIso8601String(),
            'layers' => ['mariadb' => ['ok' => true, 'detail' => 'GTID 0-1-42', 'lag_seconds' => 0.0, 'at' => now()->toIso8601String()]]]]);

        $this->get('/mirror')->assertOk()->assertSee('Primár neodpovídá')->assertSee('MariaDB (replikace)')->assertSee('GTID 0-1-42');
    }

    public function test_the_floating_ip_is_saved(): void
    {
        $this->state('primary');
        $this->agent->shouldReceive('call')->with('mirror.interfaces')->andReturn([['name' => 'eth0', 'addresses' => ['192.168.1.10/24']]]);
        $this->agent->shouldReceive('call')->once()->with('mirror.vip_set', ['address' => '192.168.1.30', 'interface' => 'eth0'])->andReturn([]);

        $this->pageAction(Livewire::test(MirrorPage::class), 'vip', ['address' => '192.168.1.30', 'interface' => 'eth0'])
            ->assertHasNoErrors()->assertNotified('Plovoucí IP uložena');
    }

    public function test_the_floating_ip_needs_an_ipv4_address(): void
    {
        $this->state('primary');
        $this->agent->shouldNotReceive('call')->with('mirror.vip_set', Mockery::any());

        $this->pageAction(Livewire::test(MirrorPage::class), 'vip', ['address' => 'fe80::1'])->assertHasErrors();
    }

    public function test_the_page_shows_who_holds_the_floating_ip(): void
    {
        $this->state('standby', ['vip' => ['address' => '192.168.1.30', 'prefix' => 24, 'interface' => 'eth0', 'held' => false],
            'status' => ['peer' => ['vip' => '192.168.1.30', 'panel_url' => 'https://ldc1.firma.local']]]);

        $this->get('/mirror')->assertOk()->assertSee('192.168.1.30/24')->assertSee('drží druhý server')->assertSee('https://ldc1.firma.local');
    }

    public function test_a_standby_says_where_the_sites_are_managed(): void
    {
        $this->state('standby', ['status' => ['peer' => ['panel_url' => 'https://ldc1.firma.local']]]);

        $this->get('/mirror')->assertOk()->assertSee('Tento server je zrcadlo.')->assertSee('Otevřít panel aktivního serveru')->assertSee('https://ldc1.firma.local');
    }

    public function test_the_serving_node_shows_no_standby_banner(): void
    {
        $this->state('primary');

        $this->get('/mirror')->assertOk()->assertDontSee('Tento server je zrcadlo.');
    }

    public function test_alerts_report_the_mirror(): void
    {
        $this->state('standby', ['status' => ['peer_up' => false, 'peer_error' => 'timeout',
            'layers' => ['sites' => ['ok' => false, 'error' => 'rsync: connection unexpectedly closed'], 'postgresql' => ['ok' => true, 'lag_seconds' => 900]]]]);

        $keys = array_column(app(Alerts::class)->mirror(), 0);

        $this->assertContains('mirror:primary-down', $keys);
        $this->assertContains('mirror:layer:sites', $keys);
        $this->assertContains('mirror:lag:postgresql', $keys);
    }

    public function test_the_active_node_repoints_cloudflare(): void
    {
        $this->state('primary', ['public_ipv4' => '203.0.113.20', 'peer' => ['public_ipv4' => '203.0.113.10'],
            'may_point_dns' => true, 'dns_hosts' => ['example.com', 'www.example.com']]);
        $this->fakeCloudflare();

        $this->artisan('panel:mirror-dns')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/dns_records/r2') && $r['content'] === '203.0.113.20');
        // The other node's own panel stays where it is.
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/dns_records/r3'));
    }

    public function test_a_primary_the_agent_does_not_confirm_leaves_cloudflare_alone(): void
    {
        $this->state('primary', ['public_ipv4' => '203.0.113.20', 'peer' => ['public_ipv4' => '203.0.113.10'],
            'may_point_dns' => false, 'dns_hosts' => ['example.com', 'www.example.com']]);
        $this->fakeCloudflare();

        $this->artisan('panel:mirror-dns')->assertSuccessful();

        Http::assertNothingSent();
    }

    private function fakeCloudflare(): void
    {
        DnsZone::acrossClients()->create(['domain' => 'example.com', 'provider' => DnsProvider::Cloudflare, 'cloudflare_zone_id' => 'z1', 'cloudflare_token' => 't']);
        Http::fake([
            'api.cloudflare.com/client/v4/zones/z1/dns_records?*' => Http::response(['success' => true, 'result' => [
                ['id' => 'r1', 'name' => 'example.com', 'type' => 'A', 'content' => '203.0.113.10'],
                ['id' => 'r2', 'name' => 'www.example.com', 'type' => 'A', 'content' => '203.0.113.10'],
                ['id' => 'r3', 'name' => 'srv1.example.com', 'type' => 'A', 'content' => '203.0.113.10'],
            ]]),
            'api.cloudflare.com/client/v4/zones/z1/dns_records/*' => Http::response(['success' => true, 'result' => []]),
        ]);
    }

    public function test_settings_go_to_the_agent_in_one_request(): void
    {
        $this->state('primary');
        $this->agent->shouldReceive('call')->once()->with('mirror.settings', Mockery::on(fn (array $p) => $p['auto_failover'] === false && $p['witness'] === []))->andReturn([]);

        $this->pageAction(Livewire::test(MirrorPage::class), 'settings', ['witness' => [], 'auto_failover' => false, 'failover_after' => 60, 'sync_every' => 60, 'lan_address' => '192.168.1.10'])
            ->assertHasNoErrors()->assertNotified('Uloženo');
    }

    public function test_a_standby_refuses_a_new_site_before_storing_it(): void
    {
        $this->state('standby', ['status' => ['peer' => ['panel_url' => 'https://ldc1.firma.local']]]);
        $this->agent->shouldNotReceive('call')->with('site.create', Mockery::any());

        try {
            app(CreateSite::class)->register(['domain' => 'shop.firma.local', 'framework' => 'laravel']);
            $this->fail('a standby stored a site');
        } catch (AgentException $e) {
            $this->assertStringContainsString('https://ldc1.firma.local', $e->getMessage());
        }
        $this->assertSame(0, Site::query()->count());
    }

    public function test_a_promotion_keeps_this_nodes_game_servers(): void
    {
        $client = Client::factory()->create(['linux_group' => 'cl_acme']);
        GameServer::query()->forceCreate(['id' => 7, 'client_id' => $client->id, 'name' => 'Local', 'game' => 'minecraft', 'flavor' => 'paper', 'version' => '1.21.10', 'port' => 25565, 'memory_mb' => 2048, 'status' => 'active']);
        $file = tempnam(sys_get_temp_dir(), 'node-local');
        $this->artisan('panel:mirror-node-local', ['action' => 'export', 'path' => $file])->assertSuccessful();

        // The primary's database arrives: its own game server, its client ids.
        GameServer::query()->delete();
        Client::query()->delete();
        $primaryClient = Client::factory()->create(['linux_group' => 'cl_acme']);
        GameServer::query()->forceCreate(['id' => 3, 'name' => 'Primary', 'game' => 'minecraft', 'flavor' => 'paper', 'version' => '1.21.10', 'port' => 25566, 'memory_mb' => 2048, 'status' => 'active']);

        $this->artisan('panel:mirror-node-local', ['action' => 'import', 'path' => $file])->assertSuccessful();

        $this->assertSame(['Local'], GameServer::query()->pluck('name')->all());
        $this->assertSame(7, GameServer::query()->value('id'));
        $this->assertSame($primaryClient->id, GameServer::query()->value('client_id'));
        @unlink($file);
    }

    public function test_a_standby_never_touches_cloudflare(): void
    {
        $this->state('standby', ['public_ipv4' => '203.0.113.20', 'peer' => ['public_ipv4' => '203.0.113.10']]);
        Http::fake();

        $this->artisan('panel:mirror-dns')->assertSuccessful();

        Http::assertNothingSent();
    }
}
