<?php

namespace Tests\Feature;

use App\Filament\Resources\Sites\Pages\DeploySite;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DeployTest extends TestCase
{
    use RefreshDatabase;

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

        Livewire::test(DeploySite::class, ['record' => $this->site->id])
            ->callAction('settings', ['repo' => 'https://user:ghp_token@github.com/acme/app.git', 'branch' => 'main', 'keep' => 5])
            ->assertHasActionErrors(['repo']);
    }

    public function test_settings_are_sent_to_the_agent(): void
    {
        $this->agent->shouldReceive('call')->once()->with('deploy.config', [
            'domain' => 'app.example.com', 'repo' => 'https://github.com/acme/app.git', 'branch' => 'release/2',
            'migrate' => false, 'assets' => true, 'auto_rollback' => true, 'keep' => 3,
        ])->andReturn([]);

        Livewire::test(DeploySite::class, ['record' => $this->site->id])
            ->callAction('settings', ['repo' => 'https://github.com/acme/app.git', 'branch' => 'release/2', 'migrate' => false, 'assets' => true, 'auto_rollback' => true, 'keep' => 3])
            ->assertHasNoActionErrors();
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

        Livewire::test(DeploySite::class, ['record' => $this->site->id])
            ->callAction('deploy')
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

        Livewire::test(DeploySite::class, ['record' => $this->site->id])
            ->callAction('rollback', ['release' => 'initial'])
            ->assertNotified('Web běží na releasu initial');
    }

    public function test_env_is_read_and_saved_through_the_agent(): void
    {
        $this->agent->shouldReceive('call')->once()->with('env.get', ['domain' => 'app.example.com'])->andReturn(['content' => "APP_NAME=Shop\n"]);
        $this->agent->shouldReceive('call')->once()->with('env.set', ['domain' => 'app.example.com', 'content' => "APP_NAME=Shop\nAPP_KEY=base64:x"])->andReturn([]);

        Livewire::test(DeploySite::class, ['record' => $this->site->id])
            ->mountAction('env')
            ->assertActionDataSet(['content' => "APP_NAME=Shop\n"])
            ->setActionData(['content' => "APP_NAME=Shop\r\nAPP_KEY=base64:x"])
            ->callMountedAction()
            ->assertNotified('.env uložen');
    }
}
