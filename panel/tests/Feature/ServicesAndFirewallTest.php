<?php

namespace Tests\Feature;

use App\Actions\Services\SyncServices;
use App\Enums\ServiceType;
use App\Livewire\Server\ListFirewallRules;
use App\Livewire\Server\ListServices;
use App\Livewire\Sites\ListSites;
use App\Models\FirewallRule;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class ServicesAndFirewallTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    /** @var list<array<string, string>> */
    private array $units = [
        ['unit' => 'panel-proxy.service', 'load' => 'loaded', 'active' => 'active', 'sub' => 'running', 'enabled' => 'enabled'],
        ['unit' => 'php8.3-fpm.service', 'load' => 'loaded', 'active' => 'active', 'sub' => 'running', 'enabled' => 'enabled'],
        ['unit' => 'php8.4-fpm.service', 'load' => 'loaded', 'active' => 'active', 'sub' => 'running', 'enabled' => 'enabled'],
        ['unit' => 'valkey-server.service', 'load' => 'loaded', 'active' => 'failed', 'sub' => 'failed', 'enabled' => 'enabled'],
        ['unit' => 'wspanel-web_my-shop_cz-horizon.service', 'load' => 'loaded', 'active' => 'inactive', 'sub' => 'dead', 'enabled' => 'enabled'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn([
            'php' => [['version' => '8.4', 'installed' => true]],
            'php_default' => '8.4',
            'panel_units' => ['panel-proxy.service', 'panel-web.service', 'php8.4-fpm.service'],
            'workers' => ['laravel' => ['horizon', 'queue', 'scheduler'], 'symfony' => ['messenger', 'scheduler']],
        ])->byDefault();
        $this->agent->shouldReceive('call')->with('sys.services')->andReturnUsing(fn () => $this->units)->byDefault();
        $this->agent->shouldReceive('call')->with('site.health', Mockery::any())->andReturn(['ok' => true, 'status' => 200])->byDefault();
        $this->agent->shouldReceive('call')->with('ssl.status')->andReturn([])->byDefault();
        $this->agent->shouldReceive('call')->with('cron.list', Mockery::any())->andReturn([])->byDefault();
        $this->agent->shouldReceive('call')->with('sys.resources')->andReturn([
            'cpus' => 4, 'load' => [0.5, 0.4, 0.3],
            'memory' => ['total' => 8 * 1024 ** 3, 'available' => 6 * 1024 ** 3],
            'disks' => [['path' => '/', 'total' => 100 * 1024 ** 3, 'free' => 40 * 1024 ** 3]],
        ])->byDefault();
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.13.5'])]);
        $this->agent->shouldReceive('call')->with('fw.list')->andReturn([
            'active' => true,
            'protected_ports' => [22, 80, 443],
            'rules' => [
                ['to' => '22/tcp', 'port' => 22, 'proto' => 'tcp', 'action' => 'allow', 'from' => 'Anywhere', 'comment' => 'ssh', 'ipv4' => true, 'ipv6' => true, 'managed' => false],
                ['to' => '5432/tcp', 'port' => 5432, 'proto' => 'tcp', 'action' => 'allow', 'from' => '10.0.0.0/24', 'comment' => 'wspanel', 'ipv4' => true, 'ipv6' => false, 'managed' => true],
            ],
        ])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    public function test_sync_mirrors_units_and_maps_workers_to_sites(): void
    {
        $site = Site::create(['domain' => 'my-shop.cz', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active', 'system_user' => 'web_my-shop_cz']);
        Service::create(['unit' => 'gone.service', 'type' => 'valkey']);

        $this->artisan('panel:sync')->assertSuccessful();

        $this->assertSame(5, Service::count());
        $this->assertDatabaseMissing('services', ['unit' => 'gone.service']);
        $worker = Service::where('type', 'worker')->sole();
        $this->assertSame('my-shop.cz', $worker->instance);
        $this->assertSame('horizon', $worker->worker);
        $this->assertTrue($worker->site->is($site));
        $this->assertSame(['service' => 'worker', 'domain' => 'my-shop.cz', 'worker' => 'horizon'], $worker->agentParams());
        $this->assertSame(['service' => 'php-fpm', 'version' => '8.3'], Service::where('unit', 'php8.3-fpm.service')->sole()->agentParams());
        $this->assertSame(2, FirewallRule::count());
    }

    public function test_unknown_units_are_ignored(): void
    {
        $this->assertNull(SyncServices::parseUnit('sshd.service'));
        $this->assertNull(SyncServices::parseUnit('wspanel-root-horizon.service'));
        $this->assertSame(ServiceType::PhpFpm->value, SyncServices::parseUnit('php8.2-fpm.service')['type']);
    }

    public function test_restart_goes_through_the_agent(): void
    {
        $this->artisan('panel:sync');
        $fpm = Service::where('unit', 'php8.3-fpm.service')->sole();
        $this->agent->shouldReceive('call')->once()->with('service.restart', ['service' => 'php-fpm', 'version' => '8.3'])->andReturn([]);

        $this->tableAction(Livewire::test(ListServices::class), $fpm, 'restart')->assertHasNoErrors();
    }

    public function test_panel_units_cannot_be_stopped_but_others_can(): void
    {
        $this->artisan('panel:sync');
        $list = Livewire::test(ListServices::class);
        $unit = fn (string $name) => Service::where('unit', $name)->sole();
        $this->assertFalse($this->tableActionVisible($list, 'stop', $unit('panel-proxy.service')));
        $this->assertFalse($this->tableActionVisible($list, 'stop', $unit('php8.4-fpm.service')));
        $this->assertTrue($this->tableActionVisible($list, 'stop', $unit('php8.3-fpm.service')));
        $this->assertTrue($this->tableActionVisible($list, 'reset', $unit('valkey-server.service')));
        $this->assertFalse($this->tableActionVisible($list, 'reload', $unit('valkey-server.service')));
    }

    public function test_agent_errors_are_shown_not_thrown(): void
    {
        $this->artisan('panel:sync');
        $this->agent->shouldReceive('call')->with('service.stop', Mockery::any())
            ->andThrow(new AgentException('forbidden', 'cannot be stopped', 'service.stop'));

        $this->tableAction(Livewire::test(ListServices::class), Service::where('unit', 'php8.3-fpm.service')->sole(), 'stop')
            ->assertNotified('cannot be stopped', 'error');
    }

    public function test_adding_a_firewall_rule(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('fw.allow', ['port' => 5432, 'proto' => 'tcp', 'from' => '10.0.0.7/24'])
            ->andReturn(['from' => '10.0.0.0/24']);

        $this->headerTableAction(Livewire::test(ListFirewallRules::class), 'add', ['action' => 'allow', 'port' => '5432', 'proto' => 'tcp', 'from' => '10.0.0.7/24', 'description' => 'DB z interní sítě'])
            ->assertHasNoErrors();

        $this->assertSame('DB z interní sítě', FirewallRule::where('port', 5432)->sole()->description);
    }

    public function test_firewall_form_validation(): void
    {
        $this->agent->shouldNotReceive('call')->with('fw.allow', Mockery::any());

        $this->headerTableAction(Livewire::test(ListFirewallRules::class), 'add', ['action' => 'allow', 'port' => '70000', 'proto' => 'tcp', 'from' => 'kdekoli'])
            ->assertHasErrors();
        $this->assertSame(0, FirewallRule::where('port', 70000)->count());
    }

    public function test_protected_rules_cannot_be_deleted(): void
    {
        $this->artisan('panel:sync');
        $list = Livewire::test(ListFirewallRules::class);
        $this->assertFalse($this->tableActionVisible($list, 'delete', FirewallRule::where('port', 22)->sole()));
        $this->assertTrue($this->tableActionVisible($list, 'delete', FirewallRule::where('port', 5432)->sole()));

        $this->agent->shouldReceive('call')->once()
            ->with('fw.delete', ['action' => 'allow', 'port' => 5432, 'proto' => 'tcp', 'from' => '10.0.0.0/24'])
            ->andReturn(['deleted' => true]);
        $this->tableAction(Livewire::test(ListFirewallRules::class), FirewallRule::where('port', 5432)->sole(), 'delete');
    }

    public function test_worker_types_follow_the_framework(): void
    {
        $site = Site::create(['domain' => 'sf.example.com', 'framework' => 'symfony', 'php_version' => '8.4', 'status' => 'active']);
        $this->agent->shouldReceive('call')->once()
            ->with('worker.create', ['domain' => 'sf.example.com', 'worker' => 'messenger'])
            ->andReturn(['started' => false]);

        $this->tableAction(Livewire::test(ListSites::class), $site, 'addWorker', ['worker' => 'horizon'])
            ->assertHasErrors();
        $this->tableAction(Livewire::test(ListSites::class), $site, 'addWorker', ['worker' => 'messenger'])
            ->assertHasNoErrors();
    }

    public function test_firewall_port_range_is_checked_on_the_server(): void
    {
        $this->agent->shouldNotReceive('call')->with('fw.allow', Mockery::any());

        $this->headerTableAction(Livewire::test(ListFirewallRules::class), 'add', ['action' => 'allow', 'port' => '70000', 'proto' => 'tcp', 'from' => ''])
            ->assertHasErrors();
    }
}
