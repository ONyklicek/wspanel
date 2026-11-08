<?php

namespace Tests\Feature;

use App\Livewire\Sites\ViewSite;
use App\Models\Database;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SiteCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('site.credentials', ['domain' => 'wp.example.com'])->andReturn([
            'database' => ['host' => '127.0.0.1', 'port' => '3306', 'database' => 'wp_db', 'username' => 'wp_db', 'password' => 'Tajne-heslo-1'],
            'valkey' => ['REDIS_HOST' => '/run/wspanel-valkey/web_wp_example_com/valkey.sock'],
        ]);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
        $this->actingAs(User::factory()->create());
        $this->site = Site::create(['domain' => 'wp.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active',
            'system_user' => 'web_wp_example_com', 'home' => '/srv/sites/wp.example.com', 'valkey' => ['maxmemory_mb' => 64]]);
        Database::create(['engine' => 'mariadb', 'name' => 'wp_db', 'site_id' => $this->site->id]);
    }

    public function test_the_site_overview_shows_access_credentials_on_request(): void
    {
        Livewire::test(ViewSite::class, ['record' => $this->site->id])
            ->call('mountAction', 'credentials')
            ->assertSee('Tajne-heslo-1')
            ->assertSee('wp_db')
            ->assertSee('web_wp_example_com')
            ->assertSee('valkey.sock');
    }
}
