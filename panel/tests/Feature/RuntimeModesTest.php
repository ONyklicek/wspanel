<?php

namespace Tests\Feature;

use App\Actions\Services\SyncServices;
use App\Enums\SiteMode;
use App\Livewire\Sites\CreateSitePage as CreateSite;
use App\Livewire\Sites\ListSites;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Wire\Sites\SiteActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class RuntimeModesTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn([
            'php' => [['version' => '8.3', 'installed' => true], ['version' => '8.4', 'installed' => true]],
            'php_default' => '8.4',
            'worker_php' => '8.5',
            'node' => ['22', '24'],
            'workers' => ['laravel' => ['horizon', 'queue', 'scheduler'], 'symfony' => ['messenger', 'scheduler']],
            'panel_units' => [],
        ])->byDefault();
        $this->agent->shouldReceive('call')->with('sys.services')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    public function test_creating_a_node_site_sends_only_the_node_version(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('site.create', ['domain' => 'ssr.example.com', 'mode' => 'node', 'framework' => 'nuxt', 'node_version' => '24', 'tls' => 'auto'])
            ->andReturn(['mode' => 'node', 'framework' => 'nuxt', 'node_version' => '24', 'port' => 30001, 'php_version' => '', 'system_user' => 'web_ssr_example_com']);

        $page = $this->fillForm(Livewire::test(CreateSite::class), ['domain' => 'ssr.example.com', 'mode' => 'node'])
            ->assertSet('data.framework', 'nuxt');   // framework follows the mode
        $this->fillForm($page, ['node_version' => '24'])
            ->call('save')
            ->assertHasNoErrors();

        $site = Site::sole();
        $this->assertSame(SiteMode::Node, $site->mode);
        $this->assertSame(30001, $site->port);
        $this->assertNull($site->php_version);
        $this->assertSame('Node 24', $site->runtimeLabel());
    }

    public function test_worker_site_gets_frankenphp_php_version_from_agent(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('site.create', ['domain' => 'w.example.com', 'mode' => 'worker', 'framework' => 'symfony', 'tls' => 'auto'])
            ->andReturn(['mode' => 'worker', 'framework' => 'symfony', 'php_version' => '8.5', 'system_user' => 'web_w_example_com']);

        $this->fillForm(Livewire::test(CreateSite::class), ['domain' => 'w.example.com', 'mode' => 'worker', 'framework' => 'symfony'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('PHP 8.5 · FrankenPHP', Site::sole()->runtimeLabel());
    }

    public function test_framework_must_fit_the_mode(): void
    {
        $this->agent->shouldNotReceive('call')->with('site.create', Mockery::any());

        $this->fillForm(Livewire::test(CreateSite::class), ['domain' => 'x.example.com', 'mode' => 'worker', 'framework' => 'php'])
            ->call('save')
            ->assertHasErrors(['data.framework']);
    }

    public function test_switch_mode_updates_the_site(): void
    {
        $site = Site::create(['domain' => 'm.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.3', 'status' => 'active']);
        $this->agent->shouldReceive('call')->once()
            ->with('site.switch_mode', ['domain' => 'm.example.com', 'mode' => 'worker', 'framework' => 'laravel'])
            ->andReturn(['mode' => 'worker', 'framework' => 'laravel', 'php_version' => '8.5', 'system_user' => 'web_m_example_com']);

        $this->siteSetting($site, 'switchMode', ['mode' => 'worker', 'framework' => 'laravel'])
            ->assertHasNoErrors();

        $this->assertSame(SiteMode::Worker, $site->refresh()->mode);
        $this->assertSame('8.5', $site->php_version);
    }

    public function test_set_version_is_hidden_for_worker_sites_and_failures_are_reported(): void
    {
        $worker = Site::create(['domain' => 'w.example.com', 'mode' => 'worker', 'framework' => 'laravel', 'php_version' => '8.5', 'status' => 'active']);
        $classic = Site::create(['domain' => 'c.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.3', 'status' => 'active']);
        $this->agent->shouldReceive('call')->with('site.set_version', ['domain' => 'c.example.com', 'version' => '8.4'])
            ->andThrow(new AgentException('op_failed', 'php-fpm 8.4 config invalid', 'site.set_version'));

        $setVersion = collect(SiteActions::settings())->first(fn ($a) => $a->getName() === 'setVersion');
        $this->assertTrue($setVersion->isHidden($worker));
        $this->assertFalse($setVersion->isHidden($classic));
        $this->siteSetting($classic, 'setVersion', ['version' => '8.4'])
            ->assertNotified('config invalid', 'error');

        $this->assertSame('8.3', $classic->refresh()->php_version);
    }

    public function test_health_check_is_stored(): void
    {
        $site = Site::create(['domain' => 'h.example.com', 'mode' => 'node', 'framework' => 'node', 'node_version' => '22', 'port' => 30000, 'status' => 'active']);
        $this->agent->shouldReceive('call')->with('site.health', ['domain' => 'h.example.com'])
            ->andReturn(['ok' => false, 'error' => 'dial tcp 127.0.0.1:30000: connect: connection refused']);

        $this->tableAction(Livewire::test(ListSites::class), $site, 'checkHealth');

        $site->refresh();
        $this->assertFalse($site->health_ok);
        $this->assertSame('Server spojení odmítl (nic na portu neposlouchá).', $site->health_error);
        $this->assertStringContainsString('connection refused', $site->getRawOriginal('health_error'));
    }

    public function test_app_units_are_mirrored_as_app_services(): void
    {
        Site::create(['domain' => 'n.example.com', 'mode' => 'node', 'framework' => 'node', 'node_version' => '22', 'status' => 'active', 'system_user' => 'web_n_example_com']);
        $this->agent->shouldReceive('call')->with('sys.services')->andReturn([
            ['unit' => 'wspanel-web_n_example_com-app.service', 'load' => 'loaded', 'active' => 'active', 'sub' => 'running', 'enabled' => 'enabled'],
        ]);
        app(SyncServices::class)->handle();

        $app = Service::sole();
        $this->assertSame(['service' => 'app', 'domain' => 'n.example.com'], $app->agentParams());
        $this->assertSame('Node 22 · n.example.com', $app->label());
    }
}
