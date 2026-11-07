<?php

namespace Tests\Feature;

use App\Filament\Resources\Sites\Pages\ListSites;
use App\Filament\Resources\Sites\Pages\ViewSite;
use App\Filament\Resources\Sites\Widgets\SiteLogs;
use App\Filament\Resources\Sites\Widgets\SiteProcesses;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SiteControlTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn(['php' => [['version' => '8.4', 'installed' => true]], 'panel_units' => []])->byDefault();
        $this->agent->shouldReceive('call')->with('sys.services')->andReturn([])->byDefault();
        $this->agent->shouldReceive('call')->with('site.health', Mockery::any())->andReturn(['ok' => true, 'status' => 200])->byDefault();
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

        Livewire::test(ListSites::class)->callTableAction('disable', $site)->assertHasNoTableActionErrors();
        $this->assertFalse($site->refresh()->enabled);
        $this->assertSame('Vypnutý', $site->stateLabel());

        $this->agent->shouldReceive('call')->once()->with('site.enable', ['domain' => 'app.example.com'])->andReturn(['enabled' => true]);
        Livewire::test(ViewSite::class, ['record' => $site->id])->callAction('enable')->assertHasNoActionErrors();
        $this->assertTrue($site->refresh()->enabled);
    }

    public function test_restart_can_include_workers(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->once()->with('site.restart', ['domain' => 'app.example.com', 'workers' => true])
            ->andReturn(['restarted' => ['php8.4-fpm.service (reload)']]);

        Livewire::test(ViewSite::class, ['record' => $site->id])
            ->callAction('restart', data: ['workers' => true])
            ->assertHasNoActionErrors()
            ->assertNotified();
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

        Livewire::test(SiteProcesses::class, ['record' => $site])
            ->assertSee('Fronta (queue:work)')
            ->assertSee('Úloha #3')
            ->assertSee('50.0 MB')
            ->callTableAction('restart', 'wspanel-web_app_example_com-queue.service')
            ->assertNotified();
    }

    public function test_logs_widget_reads_the_selected_source(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->once()
            ->with('site.logs', ['domain' => 'app.example.com', 'source' => 'framework', 'lines' => 200])
            ->andReturn(['text' => "[2026-11-07] production.ERROR: boom\n", 'file' => '/srv/sites/app.example.com/shared/storage/logs/laravel.log']);

        Livewire::test(SiteLogs::class, ['record' => $site])
            ->set('source', 'framework')
            ->assertSee('production.ERROR: boom');
    }
}
