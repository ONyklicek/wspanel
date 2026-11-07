<?php

namespace Tests\Feature;

use App\Actions\Services\SyncServices;
use App\Enums\ServiceType;
use App\Filament\Resources\FirewallRules\Pages\ListFirewallRules;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Models\FirewallRule;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ServicesAndFirewallTest extends TestCase
{
    use RefreshDatabase;

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

        Livewire::test(ListServices::class)->callTableAction('restart', $fpm)->assertHasNoTableActionErrors();
    }

    public function test_panel_units_cannot_be_stopped_but_others_can(): void
    {
        $this->artisan('panel:sync');
        Livewire::test(ListServices::class)
            ->assertTableActionHidden('stop', Service::where('unit', 'panel-proxy.service')->sole())
            ->assertTableActionHidden('stop', Service::where('unit', 'php8.4-fpm.service')->sole())
            ->assertTableActionVisible('stop', Service::where('unit', 'php8.3-fpm.service')->sole())
            ->assertTableActionVisible('reset', Service::where('unit', 'valkey-server.service')->sole())
            ->assertTableActionHidden('reload', Service::where('unit', 'valkey-server.service')->sole());
    }

    public function test_agent_errors_are_shown_not_thrown(): void
    {
        $this->artisan('panel:sync');
        $this->agent->shouldReceive('call')->with('service.stop', Mockery::any())
            ->andThrow(new AgentException('forbidden', 'cannot be stopped', 'service.stop'));

        Livewire::test(ListServices::class)
            ->callTableAction('stop', Service::where('unit', 'php8.3-fpm.service')->sole())
            ->assertNotified();
    }

    public function test_adding_a_firewall_rule(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('fw.allow', ['port' => 5432, 'proto' => 'tcp', 'from' => '10.0.0.7/24'])
            ->andReturn(['from' => '10.0.0.0/24']);

        Livewire::test(ListFirewallRules::class)
            ->callAction('add', ['action' => 'allow', 'port' => '5432', 'proto' => 'tcp', 'from' => '10.0.0.7/24', 'description' => 'DB z interní sítě'])
            ->assertHasNoActionErrors();

        $this->assertSame('DB z interní sítě', FirewallRule::where('port', 5432)->sole()->description);
    }

    public function test_firewall_form_validation(): void
    {
        $this->agent->shouldNotReceive('call')->with('fw.allow', Mockery::any());

        Livewire::test(ListFirewallRules::class)
            ->callAction('add', ['action' => 'allow', 'port' => '70000', 'proto' => 'tcp', 'from' => 'kdekoli'])
            ->assertHasActionErrors(['port', 'from']);
    }

    public function test_protected_rules_cannot_be_deleted(): void
    {
        $this->artisan('panel:sync');
        Livewire::test(ListFirewallRules::class)
            ->assertTableActionHidden('delete', FirewallRule::where('port', 22)->sole())
            ->assertTableActionVisible('delete', FirewallRule::where('port', 5432)->sole());

        $this->agent->shouldReceive('call')->once()
            ->with('fw.delete', ['action' => 'allow', 'port' => 5432, 'proto' => 'tcp', 'from' => '10.0.0.0/24'])
            ->andReturn(['deleted' => true]);
        Livewire::test(ListFirewallRules::class)->callTableAction('delete', FirewallRule::where('port', 5432)->sole());
    }

    public function test_worker_types_follow_the_framework(): void
    {
        $site = Site::create(['domain' => 'sf.example.com', 'framework' => 'symfony', 'php_version' => '8.4', 'status' => 'active']);
        $this->agent->shouldReceive('call')->once()
            ->with('worker.create', ['domain' => 'sf.example.com', 'worker' => 'messenger'])
            ->andReturn(['started' => false]);

        Livewire::test(ListSites::class)
            ->callTableAction('addWorker', $site, data: ['worker' => 'horizon'])
            ->assertHasTableActionErrors(['worker']);
        Livewire::test(ListSites::class)
            ->callTableAction('addWorker', $site, data: ['worker' => 'messenger'])
            ->assertHasNoTableActionErrors();
    }

    public function test_dashboard_renders(): void
    {
        $this->artisan('panel:sync');
        $this->get('/')->assertOk()->assertSee('Služby');
    }
}
