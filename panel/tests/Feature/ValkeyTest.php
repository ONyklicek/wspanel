<?php

namespace Tests\Feature;

use App\Actions\Server\ManageKeyValue;
use App\Actions\Sites\ManageValkey;
use App\Livewire\Pages\ModulesPage;
use App\Livewire\Sites\SiteSettings;
use App\Livewire\Sites\ViewSite;
use App\Models\AgentTask;
use App\Models\Client;
use App\Models\Package;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ValkeyTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
    }

    private function site(?Client $client = null): Site
    {
        return Site::acrossClients()->create(['domain' => 'kv.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4',
            'status' => 'active', 'system_user' => 'web_kv_example_com', 'home' => '/srv/sites/kv.example.com', 'client_id' => $client?->id]);
    }

    public function test_enabling_starts_the_sites_own_server_and_remembers_it(): void
    {
        $site = $this->site();
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->once()
            ->with('valkey.set', ['domain' => 'kv.example.com', 'enabled' => true, 'server' => 'valkey', 'maxmemory_mb' => 128, 'policy' => 'noeviction', 'persist' => true, 'use' => []])
            ->andReturn(['socket' => '/run/wspanel-valkey/web_kv_example_com/valkey.sock']);

        app(ManageValkey::class)->set($site, ['enabled' => true, 'maxmemory_mb' => 128, 'policy' => 'noeviction', 'persist' => true, 'use' => []]);

        $this->assertSame(128, $site->fresh()->valkey['maxmemory_mb']);
    }

    public function test_a_site_can_run_redis_instead(): void
    {
        $site = $this->site();
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->once()
            ->with('valkey.set', ['domain' => 'kv.example.com', 'enabled' => true, 'server' => 'redis', 'maxmemory_mb' => 64, 'policy' => 'allkeys-lru', 'persist' => false, 'use' => []])
            ->andReturn(['port' => 601]);

        app(ManageValkey::class)->set($site, ['enabled' => true, 'server' => 'redis', 'maxmemory_mb' => 64]);

        $this->assertSame('redis', $site->fresh()->valkey['server']);
    }

    public function test_an_unknown_server_falls_back_to_valkey(): void
    {
        $site = $this->site();
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->once()
            ->with('valkey.set', Mockery::on(fn ($p) => $p['server'] === 'valkey'))
            ->andReturn([]);

        app(ManageValkey::class)->set($site, ['enabled' => true, 'server' => 'memcached', 'maxmemory_mb' => 64]);
    }

    public function test_a_server_with_only_redis_gives_a_site_redis(): void
    {
        $site = $this->site();
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->with('kv.status')
            ->andReturn(['servers' => ['valkey' => ['installed' => false, 'sites' => []], 'redis' => ['installed' => true, 'sites' => []]], 'default' => 'redis']);
        $this->agent->shouldReceive('call')->once()->with('valkey.set', Mockery::on(fn ($p) => $p['server'] === 'redis'))->andReturn([]);

        app(ManageValkey::class)->set($site, ['enabled' => true, 'maxmemory_mb' => 64]);

        $this->assertSame('redis', $site->fresh()->valkey['server']);
    }

    public function test_a_key_value_server_a_site_runs_is_not_taken_away(): void
    {
        $site = $this->site();
        $site->update(['valkey' => ['server' => 'redis', 'maxmemory_mb' => 64]]);
        $this->actingAs(User::factory()->create());
        $this->agent->shouldNotReceive('call')->with('kv.uninstall', Mockery::any());

        $this->expectException(AgentException::class);
        app(ManageKeyValue::class)->uninstall('redis');
    }

    public function test_the_modules_page_offers_to_add_the_missing_one_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->with('kv.status')
            ->andReturn(['servers' => ['valkey' => ['installed' => false, 'sites' => []], 'redis' => ['installed' => true, 'sites' => ['kv.example.com']]], 'default' => 'redis', 'mail' => 'redis']);

        Livewire::test(ModulesPage::class)
            ->assertSee('Nainstalovat Valkey')->assertDontSee('Odinstalovat Redis 8')
            ->assertSee('antispam pošty běží na Redis 8');
    }

    public function test_the_dialog_opens_from_the_settings_tab(): void
    {
        $site = $this->site();
        $this->actingAs(User::factory()->create());

        Livewire::test(SiteSettings::class, ['record' => $site->id])
            ->call('callInfolistAction', 'open_valkey')
            ->assertOk()
            ->assertSee('Zapnout Valkey / Redis pro tento web');
    }

    public function test_a_restart_runs_in_the_background(): void
    {
        $site = $this->site();
        $site->update(['valkey' => ['maxmemory_mb' => 64]]);
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->once()->with('valkey.restart', ['domain' => 'kv.example.com'])->andReturn([]);

        Livewire::test(ViewSite::class, ['record' => $site->id])
            ->call('callInfolistAction', 'valkeyRestart')
            ->assertDispatched('agent-task-started');

        // The test queue runs it at once; in production the panel-queue worker.
        $this->assertSame(AgentTask::DONE, AgentTask::query()->where('op', 'valkey.restart')->sole()->status);
    }

    public function test_disabling_forgets_it(): void
    {
        $site = $this->site();
        $site->update(['valkey' => ['maxmemory_mb' => 64]]);
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->once()->with('valkey.set', ['domain' => 'kv.example.com', 'enabled' => false])->andReturn([]);

        app(ManageValkey::class)->set($site, ['enabled' => false]);

        $this->assertNull($site->fresh()->valkey);
    }

    public function test_a_package_without_valkey_refuses_it(): void
    {
        $client = Client::factory()->for(Package::factory()->state(['max_valkey_mb' => 0]))->create();
        $site = $this->site($client);
        $this->actingAs(User::factory()->forClient($client)->create());
        $this->agent->shouldNotReceive('call')->with('valkey.set', Mockery::any());

        $this->expectExceptionMessage('Valkey balíček nepovoluje.');
        app(ManageValkey::class)->set($site, ['enabled' => true, 'maxmemory_mb' => 64]);
    }

    public function test_more_memory_than_the_package_allows_is_refused(): void
    {
        $client = Client::factory()->for(Package::factory()->state(['max_valkey_mb' => 64]))->create();
        $site = $this->site($client);
        $this->actingAs(User::factory()->forClient($client)->create());

        $this->expectExceptionMessage('nejvýš 64 MB');
        app(ManageValkey::class)->set($site, ['enabled' => true, 'maxmemory_mb' => 256]);
    }

    public function test_the_site_page_shows_memory_and_keys(): void
    {
        $site = $this->site();
        $site->update(['valkey' => ['maxmemory_mb' => 64, 'policy' => 'allkeys-lru', 'persist' => false]]);
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->with('valkey.status', ['domain' => 'kv.example.com'])
            ->andReturn(['enabled' => true, 'active' => 'active', 'used_memory' => 1048576, 'keys' => 42]);

        $this->get("/sites/{$site->id}")->assertOk()->assertSee('42 klíčů')->assertSee('z 64 MB');
    }
}
