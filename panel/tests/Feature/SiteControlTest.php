<?php

namespace Tests\Feature;

use App\Actions\Sites\CreateSite;
use App\Actions\Sites\ManagePhpSettings;
use App\Livewire\Sites\ListSites;
use App\Livewire\Sites\SiteLogs;
use App\Livewire\Sites\SiteProcesses;
use App\Livewire\Sites\ViewSite;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class SiteControlTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn(['php' => [['version' => '8.4', 'installed' => true]], 'panel_units' => []])->byDefault();
        $this->agent->shouldReceive('call')->with('sys.services')->andReturn([])->byDefault();
        $this->agent->shouldReceive('call')->with('site.health', Mockery::any())->andReturn(['ok' => true, 'status' => 200])->byDefault();
        $this->agent->shouldReceive('call')->with('site.processes', Mockery::any())->andReturn(['processes' => []])->byDefault();
        $this->agent->shouldReceive('call')->with('deploy.history', Mockery::any())->andReturn(['deploys' => []])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    private function site(array $attributes = []): Site
    {
        return Site::create($attributes + [
            'domain' => 'app.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4',
            'status' => 'active', 'system_user' => 'web_app_example_com', 'home' => '/srv/sites/app.example.com',
            'doc_root' => '/srv/sites/app.example.com/current/public',
        ]);
    }

    public function test_detail_page_shows_the_site(): void
    {
        $site = $this->site();

        $this->get("/sites/{$site->id}")->assertOk()->assertSee('app.example.com')->assertSee('web_app_example_com');
    }

    public function test_site_links_keep_the_panel_port(): void
    {
        config(['app.url' => 'https://panel.localhost:8443']);
        $this->assertSame('https://app.example.com:8443/', $this->site()->url());

        config(['app.url' => 'https://panel.example.com']);
        $this->assertSame('https://app.example.com/', Site::first()->url());
    }

    public function test_disabling_and_enabling_a_site(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->once()->with('site.disable', ['domain' => 'app.example.com'])->andReturn(['enabled' => false]);

        $this->tableAction(Livewire::test(ListSites::class), $site, 'disable')->assertHasNoErrors();
        $this->assertFalse($site->refresh()->enabled);
        $this->assertSame('Vypnutý', $site->stateLabel());

        $this->agent->shouldReceive('call')->once()->with('site.enable', ['domain' => 'app.example.com'])->andReturn(['enabled' => true]);
        $this->pageAction(Livewire::test(ViewSite::class, ['record' => $site->id]), 'enable')->assertHasNoErrors();
        $this->assertTrue($site->refresh()->enabled);
    }

    public function test_restart_can_include_workers(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->once()->with('site.restart', ['domain' => 'app.example.com', 'workers' => true])
            ->andReturn(['restarted' => ['php8.4-fpm.service (reload)']]);

        $page = $this->pageAction(Livewire::test(ViewSite::class, ['record' => $site->id]), 'restart', ['workers' => true]);
        $page->assertHasNoErrors()->assertNotified(type: 'success');
    }

    public function test_processes_are_listed_and_controlled(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->with('site.processes', ['domain' => 'app.example.com'])->andReturn(['processes' => [
            ['unit' => 'wspanel-web_app_example_com-queue.service', 'kind' => 'worker', 'name' => 'queue', 'active' => 'active', 'sub' => 'running', 'enabled' => 'enabled', 'restarts' => 0, 'memory' => 52428800],
            ['unit' => 'wspanel-web_app_example_com-cron-3.timer', 'kind' => 'task', 'name' => '3', 'active' => 'active', 'sub' => 'waiting', 'enabled' => 'enabled', 'restarts' => 0],
        ]]);
        $this->agent->shouldReceive('call')->once()
            ->with('service.restart', ['service' => 'worker', 'domain' => 'app.example.com', 'worker' => 'queue'])
            ->andReturn(['unit' => 'x']);

        $list = Livewire::test(SiteProcesses::class, ['site' => $site->id])
            ->assertSee('Fronta (artisan queue:work)')
            ->assertSee('Úloha #3')
            ->assertSee('50,0 MB');
        $this->tableAction($list, 'wspanel-web_app_example_com-queue.service', 'restart')->assertNotified(type: 'success');
    }

    public function test_a_crash_looping_worker_can_be_stopped_and_reset_and_a_task_paused(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->with('site.processes', ['domain' => 'app.example.com'])->andReturn(['processes' => [
            ['unit' => 'wspanel-web_app_example_com-queue.service', 'kind' => 'worker', 'name' => 'queue', 'active' => 'activating', 'sub' => 'auto-restart', 'enabled' => 'enabled', 'restarts' => 14],
            ['unit' => 'wspanel-web_app_example_com-cron-3.timer', 'kind' => 'task', 'name' => '3', 'active' => 'active', 'sub' => 'waiting', 'enabled' => 'enabled', 'restarts' => 0],
            ['unit' => 'wspanel-web_app_example_com-valkey.service', 'kind' => 'valkey', 'name' => 'valkey', 'active' => 'failed', 'sub' => 'failed', 'enabled' => 'enabled', 'restarts' => 0],
        ]]);
        $this->agent->shouldReceive('call')->once()->with('service.stop', ['service' => 'worker', 'domain' => 'app.example.com', 'worker' => 'queue'])->andReturn([]);
        $this->agent->shouldReceive('call')->once()->with('service.reset', ['service' => 'worker', 'domain' => 'app.example.com', 'worker' => 'queue'])->andReturn([]);
        $this->agent->shouldReceive('call')->once()->with('service.stop', ['service' => 'task', 'domain' => 'app.example.com', 'worker' => '3'])->andReturn([]);
        $this->agent->shouldReceive('call')->once()->with('service.reset', ['service' => 'site-valkey', 'domain' => 'app.example.com'])->andReturn([]);

        $list = Livewire::test(SiteProcesses::class, ['site' => $site->id])->assertSee('Valkey (Redis)');
        $this->tableAction($list, 'wspanel-web_app_example_com-queue.service', 'stop')->assertNotified(type: 'success');
        $this->tableAction($list, 'wspanel-web_app_example_com-queue.service', 'reset')->assertNotified(type: 'success');
        $this->tableAction($list, 'wspanel-web_app_example_com-cron-3.timer', 'stop')->assertNotified(type: 'success');
        $this->tableAction($list, 'wspanel-web_app_example_com-valkey.service', 'reset')->assertNotified(type: 'success');
    }

    public function test_logs_widget_reads_the_selected_source(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->once()
            ->with('site.logs', ['domain' => 'app.example.com', 'source' => 'framework', 'lines' => 200])
            ->andReturn(['text' => "[2026-11-07] production.ERROR: boom\n", 'file' => '/srv/sites/app.example.com/shared/storage/logs/laravel.log']);

        Livewire::test(SiteLogs::class, ['site' => $site->id])
            ->set('source', 'framework')
            ->assertSee('production.ERROR: boom');
    }

    public function test_internal_domains_default_to_the_local_ca(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('site.create', Mockery::on(fn ($p) => $p['domain'] === 'intranet.firma.lan' && $p['tls'] === 'internal'))
            ->andReturn(['mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'system_user' => 'web_intranet_firma_lan']);

        app(CreateSite::class)->handle(['domain' => 'intranet.firma.lan', 'framework' => 'php', 'php_version' => '8.4']);

        $this->assertSame('internal', Site::where('domain', 'intranet.firma.lan')->value('tls_mode'));
    }

    public function test_custom_certificate_is_sent_to_the_agent(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->with('ssl.status')->andReturn([])->byDefault();
        $this->agent->shouldReceive('call')->once()
            ->with('site.tls', Mockery::on(fn ($p) => $p['mode'] === 'custom' && str_starts_with($p['cert'], '-----BEGIN CERTIFICATE') && str_starts_with($p['key'], '-----BEGIN PRIVATE KEY')))
            ->andReturn(['mode' => 'custom', 'certificate' => ['issuer' => 'Firma CA', 'not_after' => '2027-01-01T00:00:00Z']]);

        $list = $this->tableAction(Livewire::test(ListSites::class), $site, 'tls', ['mode' => 'custom', 'cert' => "-----BEGIN CERTIFICATE-----\nA\n", 'key' => "-----BEGIN PRIVATE KEY-----\nB\n"]);
        $list->assertHasNoErrors()->assertNotified(type: 'success');
        $this->assertSame('custom', $site->refresh()->tls_mode);
    }

    public function test_process_log_opens_in_a_modal(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->with('site.processes', ['domain' => 'app.example.com'])->andReturn(['processes' => [
            ['unit' => 'wspanel-web_app_example_com-queue.service', 'kind' => 'worker', 'name' => 'queue', 'active' => 'failed', 'sub' => 'failed', 'enabled' => 'enabled', 'restarts' => 5],
        ]]);
        $this->agent->shouldReceive('call')->atLeast()->once()
            ->with('site.logs', ['domain' => 'app.example.com', 'source' => 'worker', 'lines' => 300, 'name' => 'queue'])
            ->andReturn(['text' => "Worker crashed: <script>x</script>\n", 'unit' => 'wspanel-web_app_example_com-queue.service']);

        Livewire::test(SiteProcesses::class, ['site' => $site->id])
            ->assertSee('chyba')
            ->call('openActionModal', 'wspanel-web_app_example_com-queue.service', 'log')
            ->assertSee('Worker crashed:')
            ->assertDontSee('<script>x</script>', false);
    }

    public function test_php_settings_are_sent_and_mirrored(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->once()
            ->with('site.php', Mockery::on(fn ($p) => $p['domain'] === 'app.example.com'
                && (array) $p['settings'] === ['memory_limit' => '512M', 'date.timezone' => 'Europe/Prague', 'display_errors' => 'on']))
            ->andReturn(['settings' => ['memory_limit' => '512M', 'date.timezone' => 'Europe/Prague', 'display_errors' => 'on']]);

        $this->tableAction(Livewire::test(ListSites::class), $site, 'phpSettings', [
            'memory_limit' => '512M', 'date__timezone' => 'Europe/Prague', 'display_errors' => 'on', 'max_input_vars' => '',
        ])->assertHasNoErrors()->assertNotified('PHP nastavení uloženo');

        $this->assertSame('512M', $site->refresh()->php_settings['memory_limit']);
        $this->get("/sites/{$site->id}")->assertSee('memory_limit 512M');
    }

    public function test_php_settings_are_checked_in_the_panel(): void
    {
        $site = $this->site();
        $this->agent->shouldNotReceive('call')->with('site.php', Mockery::any());

        foreach ([['memory_limit' => 'lots'], ['max_execution_time' => '99999'], ['date__timezone' => 'Mars/Base'], ['display_errors' => 'maybe']] as $bad) {
            $this->tableAction(Livewire::test(ListSites::class), $site, 'phpSettings', $bad)->assertHasErrors();
        }
    }

    public function test_worker_knobs_only_for_worker_sites(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->once()
            ->with('site.php', Mockery::on(fn ($p) => (array) $p['settings'] === ['memory_limit' => '1G']))
            ->andReturn(['settings' => ['memory_limit' => '1G']]);

        app(ManagePhpSettings::class)->set($site, ['memory_limit' => '1G', 'worker_threads' => '8']);
    }

    public function test_domains_and_access_are_sent_without_keeping_the_password(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->once()
            ->with('site.edge', Mockery::on(fn ($p) => $p['domain'] === 'app.example.com'
                && $p['aliases'] === ['app.example.net'] && $p['redirect_hosts'] === ['www.app.example.com']
                && $p['redirects'] === [['from' => '/old', 'to' => '/new', 'code' => 301]]
                && $p['auth_path'] === '/admin' && $p['auth_user'] === 'boss' && $p['auth_password'] === 'secret-pass'
                && $p['allow_ips'] === ['203.0.113.7'] && $p['hsts'] === true))
            ->andReturn(['edge' => ['aliases' => ['app.example.net'], 'redirect_hosts' => ['www.app.example.com'], 'auth_path' => '/admin', 'auth_user' => 'boss', 'hsts' => true]]);

        $this->tableAction(Livewire::test(ListSites::class), $site, 'edge', [
            'aliases' => ['app.example.net'], 'redirect_hosts' => ['www.app.example.com'],
            'redirects' => [['from' => '/old', 'to' => '/new', 'code' => 301]],
            'auth_mode' => 'path', 'auth_path' => '/admin', 'auth_user' => 'boss', 'auth_password' => 'secret-pass',
            'allow_ips' => '203.0.113.7', 'allow_path' => '/', 'hsts' => true,
        ])->assertHasNoErrors()->assertNotified('Domény a přístup uloženy');

        $site->refresh();
        $this->assertSame(['app.example.net'], $site->edge_settings['aliases']);
        $this->assertStringNotContainsString('secret-pass', json_encode($site->getAttributes()));
        $this->assertSame('path', \App\Actions\Sites\ManageEdge::formData($site)['auth_mode']);
    }

    public function test_domains_and_access_are_checked_in_the_panel(): void
    {
        $site = $this->site();
        $this->agent->shouldNotReceive('call')->with('site.edge', Mockery::any());

        foreach ([
            ['aliases' => ['not a domain']],
            ['redirects' => [['from' => 'old', 'to' => '/new', 'code' => 301]]],
            ['redirects' => [['from' => '/a', 'to' => 'javascript:alert(1)', 'code' => 301]]],
            ['auth_mode' => 'site', 'auth_user' => 'bad user', 'auth_password' => 'secret-pass'],
            ['allow_ips' => 'office'],
            ['headers' => [['name' => 'X-A', 'value' => 'a" b']]],
        ] as $bad) {
            $this->tableAction(Livewire::test(ListSites::class), $site, 'edge', $bad)->assertHasErrors();
        }
    }
}
