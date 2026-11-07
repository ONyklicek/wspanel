<?php

namespace Tests\Feature;

use App\Livewire\Sites\SiteDeploy as DeploySite;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class DeployTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    private Site $site;

    /** @var array<string, mixed> */
    private array $history = [
        'deploys' => [['id' => '20261107T100000Z', 'started' => '2026-11-07T10:00:00Z', 'finished' => '2026-11-07T10:01:00Z', 'status' => 'success', 'commit' => 'abc123']],
        'releases' => ['initial', '20261107T100000Z'],
        'current' => '20261107T100000Z',
        'config' => ['repo' => 'git@github.com:acme/app.git', 'branch' => 'main', 'migrate' => true, 'assets' => true, 'auto_rollback' => true, 'keep' => 5],
        'running' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('deploy.history', ['domain' => 'app.example.com'])->andReturnUsing(fn () => $this->history)->byDefault();
        $this->agent->shouldReceive('call')->with('deploy.log', Mockery::any())->andReturn(['content' => "==> Composer\n", 'offset' => 13, 'status' => 'success'])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::create(['domain' => 'app.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active']);
        $this->actingAs(User::factory()->create());
    }

    public function test_page_shows_history_and_latest_log(): void
    {
        Livewire::test(DeploySite::class, ['record' => $this->site->id])
            ->assertSee('git@github.com:acme/app.git')
            ->assertSee('20261107T100000Z')
            ->assertSet('log', "==> Composer\n")
            ->assertSet('current', '20261107T100000Z');

        $this->assertSame('success', Deployment::sole()->status);
    }

    public function test_settings_reject_credentials_in_the_repository_url(): void
    {
        $this->agent->shouldNotReceive('call')->with('deploy.config', Mockery::any());

        $this->pageAction(Livewire::test(DeploySite::class, ['record' => $this->site->id]), 'settings', ['repo' => 'https://user:ghp_token@github.com/acme/app.git', 'branch' => 'main', 'keep' => 5])
            ->assertHasErrors();
    }

    public function test_settings_are_sent_to_the_agent(): void
    {
        $this->agent->shouldReceive('call')->once()->with('deploy.config', [
            'domain' => 'app.example.com', 'repo' => 'https://github.com/acme/app.git', 'branch' => 'release/2',
            'migrate' => false, 'assets' => true, 'auto_rollback' => true, 'keep' => 3,
            'commands' => ['install:permissions', 'livewire:publish --assets'], 'env_from_deploy' => true,
        ])->andReturn([]);

        $this->pageAction(Livewire::test(DeploySite::class, ['record' => $this->site->id]), 'settings', ['repo' => 'https://github.com/acme/app.git', 'branch' => 'release/2', 'migrate' => false, 'assets' => true, 'auto_rollback' => true, 'keep' => 3,
            'commands' => "php artisan install:permissions\n\n  livewire:publish --assets ", 'env_from_deploy' => true])
            ->assertHasNoErrors();
    }

    public function test_deploy_starts_a_job_and_follows_its_log(): void
    {
        $started = false;
        $this->agent->shouldReceive('call')->once()->with('deploy.run', ['domain' => 'app.example.com'])->andReturnUsing(function () use (&$started) {
            $started = true;

            return ['job_id' => 'abcdef0123456789'];
        });
        $this->agent->shouldReceive('call')->with('deploy.history', ['domain' => 'app.example.com'])->andReturnUsing(function () use (&$started) {
            $h = $this->history;
            if (! $started) {
                return $h;
            }
            array_unshift($h['deploys'], ['id' => '20261107T110000Z', 'started' => '2026-11-07T11:00:00Z', 'status' => 'running']);
            $h['running'] = true;

            return $h;
        });
        $this->agent->shouldReceive('call')->with('deploy.log', ['domain' => 'app.example.com', 'id' => '20261107T110000Z', 'offset' => 0])
            ->andReturn(['content' => "==> Fetch source\n", 'offset' => 17, 'status' => 'running']);
        $this->agent->shouldReceive('call')->with('deploy.log', ['domain' => 'app.example.com', 'id' => '20261107T110000Z', 'offset' => 17])
            ->andReturn(['content' => "==> Composer\n", 'offset' => 30, 'status' => 'running']);

        $this->pageAction(Livewire::test(DeploySite::class, ['record' => $this->site->id]), 'deploy')
            ->assertSet('logRelease', '20261107T110000Z')
            ->assertSet('log', "==> Fetch source\n")
            ->call('refreshLog')
            ->assertSet('log', "==> Fetch source\n==> Composer\n")
            ->assertSet('logOffset', 30);
    }

    public function test_rollback_to_a_chosen_release(): void
    {
        $this->agent->shouldReceive('call')->once()->with('deploy.rollback', ['domain' => 'app.example.com', 'release' => 'initial'])
            ->andReturn(['current' => 'initial', 'healthy' => true, 'health_error' => '']);

        $this->pageAction(Livewire::test(DeploySite::class, ['record' => $this->site->id]), 'rollback', ['release' => 'initial'])
            ->assertNotified('Web běží na releasu initial');
    }

    public function test_production_opcache_toggle(): void
    {
        $this->agent->shouldReceive('call')->once()->with('deploy.config', Mockery::any())->andReturn([]);
        $this->agent->shouldReceive('call')->once()->with('site.opcache', ['domain' => 'app.example.com', 'production' => true])->andReturn([]);

        $this->pageAction(Livewire::test(DeploySite::class, ['record' => $this->site->id]), 'settings', ['repo' => 'git@github.com:acme/app.git', 'branch' => 'main', 'keep' => 5, 'opcache_production' => true])
            ->assertHasNoErrors();
    }

    public function test_env_is_read_and_saved_through_the_agent(): void
    {
        $this->agent->shouldReceive('call')->once()->with('env.get', ['domain' => 'app.example.com'])->andReturn(['content' => "APP_NAME=Shop\n"]);
        $this->agent->shouldReceive('call')->once()->with('env.set', ['domain' => 'app.example.com', 'content' => "APP_NAME=Shop\nAPP_KEY=base64:x"])->andReturn([]);

        Livewire::test(DeploySite::class, ['record' => $this->site->id])
            ->call('mountAction', 'env')
            ->assertSet('mountedActions.0.data.content', "APP_NAME=Shop\n")
            ->set('mountedActions.0.data.content', "APP_NAME=Shop\r\nAPP_KEY=base64:x")
            ->call('callMountedAction')
            ->assertNotified('.env uložen');
    }

    public function test_switching_to_polling_shows_the_last_check_and_resets_it_on_change(): void
    {
        $this->site->update(['webhook_enabled' => true, 'webhook_id' => str_repeat('a', 32), 'webhook_secret' => str_repeat('b', 64), 'deploy_trigger' => 'poll_branch',
            'deploy_polled_at' => now(), 'deploy_polled_ref' => 'abc', 'deploy_poll_error' => 'git ls-remote failed: Permission denied (publickey)']);

        $page = Livewire::test(DeploySite::class, ['record' => $this->site->id])
            ->call('mountAction', 'automation')
            ->assertSee('Permission denied (publickey)');

        $page->set('mountedActions.0.data.trigger', 'poll_tags')
            ->call('callMountedAction')
            ->assertHasNoErrors();

        $this->site->refresh();
        $this->assertSame('poll_tags', $this->site->deploy_trigger);
        $this->assertNull($this->site->deploy_polled_ref);
        $this->assertNull($this->site->deploy_poll_error);
    }
}
