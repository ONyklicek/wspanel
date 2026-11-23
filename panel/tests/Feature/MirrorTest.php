<?php

namespace Tests\Feature;

use App\Enums\DnsProvider;
use App\Livewire\Pages\MirrorPage;
use App\Models\DnsZone;
use App\Models\User;
use App\Services\Agent\AgentClient;
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
        $this->state('primary', ['public_ipv4' => '203.0.113.20', 'peer' => ['public_ipv4' => '203.0.113.10']]);
        DnsZone::acrossClients()->create(['domain' => 'example.com', 'provider' => DnsProvider::Cloudflare, 'cloudflare_zone_id' => 'z1', 'cloudflare_token' => 't']);
        Http::fake([
            'api.cloudflare.com/client/v4/zones/z1/dns_records?*' => Http::response(['success' => true, 'result' => [
                ['id' => 'r1', 'name' => 'example.com', 'type' => 'A', 'content' => '203.0.113.10'],
                ['id' => 'r2', 'name' => 'www.example.com', 'type' => 'A', 'content' => '203.0.113.10'],
            ]]),
            'api.cloudflare.com/client/v4/zones/z1/dns_records/*' => Http::response(['success' => true, 'result' => []]),
        ]);

        $this->artisan('panel:mirror-dns')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/dns_records/r2') && $r['content'] === '203.0.113.20');
    }

    public function test_a_standby_never_touches_cloudflare(): void
    {
        $this->state('standby', ['public_ipv4' => '203.0.113.20', 'peer' => ['public_ipv4' => '203.0.113.10']]);
        Http::fake();

        $this->artisan('panel:mirror-dns')->assertSuccessful();

        Http::assertNothingSent();
    }
}
