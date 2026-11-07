<?php

namespace Tests\Feature;

use App\Actions\Sites\ManageValkey;
use App\Models\Client;
use App\Models\Package;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->with('valkey.set', ['domain' => 'kv.example.com', 'enabled' => true, 'maxmemory_mb' => 128, 'policy' => 'noeviction', 'persist' => true])
            ->andReturn(['socket' => '/run/wspanel-valkey/web_kv_example_com/valkey.sock']);

        app(ManageValkey::class)->set($site, ['enabled' => true, 'maxmemory_mb' => 128, 'policy' => 'noeviction', 'persist' => true]);

        $this->assertSame(128, $site->fresh()->valkey['maxmemory_mb']);
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
