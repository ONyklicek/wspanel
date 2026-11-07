<?php

namespace Tests\Feature;

use App\Actions\Sites\CreateSite;
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
}
