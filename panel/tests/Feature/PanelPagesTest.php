<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** Every screen renders (agent mocked with plausible answers). */
class PanelPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturnUsing(fn (string $op) => match ($op) {
            'sys.versions' => ['php' => [['version' => '8.4', 'installed' => true]], 'php_default' => '8.4', 'db_engines' => ['mariadb'], 'node' => ['24'], 'worker_php' => '8.5', 'workers' => ['laravel' => ['queue']], 'panel_units' => []],
            'site.processes' => ['processes' => [['unit' => 'wspanel-web_a_example_com-queue.service', 'kind' => 'worker', 'name' => 'queue', 'active' => 'active', 'sub' => 'running', 'enabled' => 'enabled', 'restarts' => 0, 'memory' => 1048576]]],
            'deploy.history' => ['deploys' => [], 'releases' => ['initial'], 'current' => 'initial', 'running' => false, 'config' => null],
            default => [],
        });
        $this->app->instance(AgentClient::class, $agent);
        $this->actingAs(User::factory()->create());
    }

    private function site(): Site
    {
        return Site::create(['domain' => 'a.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active', 'system_user' => 'web_a_example_com', 'home' => '/srv/sites/a.example.com', 'doc_root' => '/srv/sites/a.example.com/current/public']);
    }

    public function test_site_screens_render(): void
    {
        $site = $this->site();
        foreach (['/sites', '/sites/create', "/sites/{$site->id}", "/sites/{$site->id}/deploy"] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get("/sites/{$site->id}")->assertSee('a.example.com')->assertSee('web_a_example_com');
    }
}
