<?php

namespace Tests\Feature;

use App\Actions\Services\SyncServices;
use App\Enums\SiteMode;
use App\Livewire\Pages\ModulesPage;
use App\Livewire\Sites\CreateSitePage as CreateSite;
use App\Livewire\Sites\ListSites;
use App\Models\AgentTask;
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
            'python' => ['3.12', '3.13'],
            'python_installable' => ['3.12', '3.13', '3.14'],
            'python_patches' => ['3.12' => '3.12.12', '3.13' => '3.13.5'],
            'workers' => ['laravel' => ['horizon', 'queue', 'scheduler'], 'symfony' => ['messenger', 'scheduler'], 'django' => ['celery', 'celerybeat'], 'flask' => ['celery', 'celerybeat']],
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

        $this->fillForm(Livewire::test(CreateSite::class), ['domain' => 'w.example.com', 'mode' => 'worker', 'framework' => 'symfony', 'workers' => []])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('PHP 8.5 · FrankenPHP', Site::sole()->runtimeLabel());
    }

    public function test_a_laravel_site_starts_with_the_workers_chosen_for_it(): void
    {
        $this->agent->shouldReceive('call')->once()->with('site.create', Mockery::any())
            ->andReturn(['mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'system_user' => 'web_q_example_com']);
        $this->agent->shouldReceive('call')->once()->with('worker.create', ['domain' => 'q.example.com', 'worker' => 'queue'])->andReturn(['started' => false]);
        $this->agent->shouldReceive('call')->once()->with('worker.create', ['domain' => 'q.example.com', 'worker' => 'scheduler'])->andReturn(['started' => false]);

        $page = $this->fillForm(Livewire::test(CreateSite::class), ['domain' => 'q.example.com', 'mode' => 'classic', 'framework' => 'laravel'])
            ->assertSet('data.workers', fn ($w) => in_array('queue', $w, true) && in_array('scheduler', $w, true));
        // "messenger" is Symfony's, preselected too: a Laravel site leaves it out.
        $page->call('save')->assertHasNoErrors();

        $this->assertSame(SiteMode::Classic, Site::sole()->mode);
    }

    public function test_horizon_and_a_plain_queue_are_not_chosen_together(): void
    {
        $this->agent->shouldNotReceive('call')->with('site.create', Mockery::any());

        $this->fillForm(Livewire::test(CreateSite::class), ['domain' => 'h.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'workers' => ['horizon', 'queue']])
            ->call('save')
            ->assertHasErrors(['data.workers']);
    }

    public function test_framework_must_fit_the_mode(): void
    {
        $this->agent->shouldNotReceive('call')->with('site.create', Mockery::any());

        $this->fillForm(Livewire::test(CreateSite::class), ['domain' => 'x.example.com', 'mode' => 'worker', 'framework' => 'php'])
            ->call('save')
            ->assertHasErrors(['data.framework']);
    }

    public function test_a_new_site_is_not_offered_a_runtime_the_server_lacks(): void
    {
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn([
            'php' => [['version' => '8.4', 'installed' => true]],
            'worker_php' => '8.5',
            'node' => ['24'],
            'python' => [],
            'panel_units' => [],
        ]);
        $this->agent->shouldNotReceive('call')->with('site.create', Mockery::any());

        $this->fillForm(Livewire::test(CreateSite::class), ['domain' => 'py.example.com', 'mode' => 'python'])
            ->assertDontSee(__('Django, Flask nebo FastAPI pod gunicornem / uvicornem, každý release s vlastním virtualenvem.'))
            ->assertSee(__('Režim Python se tu objeví, až Python doinstalujete v Server → Moduly.'))
            ->call('save')
            ->assertHasErrors(['data.mode']);
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

    public function test_creating_a_django_site_sends_the_python_version_and_its_app(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('site.create', ['domain' => 'dj.example.com', 'mode' => 'python', 'framework' => 'django', 'python_version' => '3.12', 'python_app' => 'shop.wsgi:application', 'tls' => 'auto'])
            ->andReturn(['mode' => 'python', 'framework' => 'django', 'python_version' => '3.12', 'python_app' => 'shop.wsgi:application', 'port' => 30002, 'system_user' => 'web_dj_example_com']);

        $page = $this->fillForm(Livewire::test(CreateSite::class), ['domain' => 'dj.example.com', 'mode' => 'python'])
            ->assertSet('data.framework', 'django');
        $this->fillForm($page, ['python_version' => '3.12', 'python_app' => 'shop.wsgi:application'])
            ->call('save')
            ->assertHasNoErrors();

        $site = Site::sole();
        $this->assertSame(SiteMode::Python, $site->mode);
        $this->assertSame('shop.wsgi:application', $site->python_app);
        $this->assertSame('Python 3.12', $site->runtimeLabel());
    }

    public function test_a_python_app_must_be_module_and_object(): void
    {
        $this->agent->shouldNotReceive('call')->with('site.create', Mockery::any());

        $page = $this->fillForm(Livewire::test(CreateSite::class), ['domain' => 'f.example.com', 'mode' => 'python']);
        $this->fillForm($page, ['framework' => 'flask', 'python_version' => '3.13', 'python_app' => 'app:create_app()'])
            ->call('save')
            ->assertHasErrors(['data.python_app']);
    }

    public function test_a_python_sites_version_and_app_are_changed_from_its_settings(): void
    {
        $site = Site::create(['domain' => 'p.example.com', 'mode' => 'python', 'framework' => 'fastapi', 'python_version' => '3.12', 'port' => 30003, 'status' => 'active']);
        $this->agent->shouldReceive('call')->once()->with('site.set_version', ['domain' => 'p.example.com', 'version' => '3.13'])
            ->andReturn(['mode' => 'python', 'framework' => 'fastapi', 'python_version' => '3.13', 'port' => 30003]);
        $this->agent->shouldReceive('call')->once()->with('site.python_app', ['domain' => 'p.example.com', 'app' => 'api.main:app'])
            ->andReturn(['domain' => 'p.example.com', 'app' => 'api.main:app', 'started' => true]);

        $this->siteSetting($site, 'setVersion', ['version' => '3.13'])->assertHasNoErrors();
        $this->siteSetting($site->refresh(), 'pythonApp', ['python_app' => 'api.main:app'])->assertHasNoErrors();

        $site->refresh();
        $this->assertSame('3.13', $site->python_version);
        $this->assertSame('api.main:app', $site->python_app);
        $phpSettings = collect(SiteActions::settings())->first(fn ($a) => $a->getName() === 'phpSettings');
        $this->assertTrue($phpSettings->isHidden($site));
    }

    public function test_python_is_installed_from_the_modules_page(): void
    {
        $this->agent->shouldReceive('call')->once()->with('python.install', ['version' => '3.14'])->andReturn(['job_id' => 'j1']);
        $this->agent->shouldReceive('call')->with('job.status', ['id' => 'j1'])->andReturn(['status' => 'done', 'output' => ['version' => '3.14', 'changed' => true]]);

        Livewire::test(ModulesPage::class)
            ->assertSee('Python 3.13')
            ->call('mountAction', 'installPython')
            ->assertSet('mountedActions.0.data.version', '3.14')
            ->call('callMountedAction')
            ->assertHasNoErrors()
            ->assertSee('Instalace Pythonu 3.14')
            ->call('pollBackgroundJobs')
            ->assertRedirect();
    }

    public function test_python_is_upgraded_to_its_newest_patch_from_the_modules_page(): void
    {
        $this->agent->shouldReceive('call')->once()->with('python.upgrade', ['version' => '3.13'])->andReturn(['job_id' => 'j2']);
        $this->agent->shouldReceive('call')->with('job.status', ['id' => 'j2'])->andReturn(['status' => 'done',
            'output' => ['version' => '3.13', 'from' => '3.13.5', 'to' => '3.13.18', 'changed' => true, 'restarted' => ['wspanel-web_a-app.service']]]);

        Livewire::test(ModulesPage::class)
            ->assertSee('Python 3.13.5')
            ->call('mountAction', 'upgradePython')
            ->assertSet('mountedActions.0.data.version', '3.13')
            ->call('callMountedAction')
            ->assertHasNoErrors()
            ->assertNotified('Spuštěno na pozadí')
            ->call('pollBackgroundJobs');
        $this->assertSame('Python aktualizován na 3.13.18', AgentTask::sole()->done_title);
        $this->assertNotNull(AgentTask::sole()->notified_at);
    }

    public function test_a_celery_worker_takes_the_sites_celery_app(): void
    {
        $flask = Site::create(['domain' => 'f.example.com', 'mode' => 'python', 'framework' => 'flask', 'python_version' => '3.13', 'port' => 30004, 'status' => 'active']);
        $django = Site::create(['domain' => 'd.example.com', 'mode' => 'python', 'framework' => 'django', 'python_version' => '3.13', 'port' => 30005, 'status' => 'active']);
        $this->agent->shouldReceive('call')->once()->with('worker.create', ['domain' => 'f.example.com', 'worker' => 'celery', 'app' => 'tasks:celery'])
            ->andReturn(['started' => false, 'app' => 'tasks:celery']);
        $this->agent->shouldReceive('call')->once()->with('worker.create', ['domain' => 'd.example.com', 'worker' => 'celerybeat'])
            ->andReturn(['started' => true, 'app' => 'shop']);

        // Flask has no convention: the app is required and checked.
        $this->tableAction(Livewire::test(ListSites::class), $flask, 'addWorker', ['worker' => 'celery'])
            ->assertHasErrors();
        $this->tableAction(Livewire::test(ListSites::class), $flask, 'addWorker', ['worker' => 'celery', 'app' => 'tasks; rm -rf /'])
            ->assertHasErrors();
        $this->tableAction(Livewire::test(ListSites::class), $flask, 'addWorker', ['worker' => 'celery', 'app' => 'tasks:celery'])
            ->assertHasNoErrors()
            ->assertNotified();
        // Django: the project package, nothing to fill in.
        $this->tableAction(Livewire::test(ListSites::class), $django, 'addWorker', ['worker' => 'celerybeat'])
            ->assertHasNoErrors();
    }
}
