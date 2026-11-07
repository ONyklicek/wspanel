<?php

namespace Tests\Feature;

use App\Actions\Sites\DeployPoller;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DeployPollerTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::create(['domain' => 'app.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active',
            'webhook_enabled' => true, 'deploy_trigger' => 'poll_branch']);
    }

    /** @param  list<array<string, mixed>>  $deploys */
    private function history(array $deploys, bool $running = false): void
    {
        $this->agent->shouldReceive('call')->with('deploy.history', ['domain' => 'app.example.com'])
            ->andReturn(['deploys' => $deploys, 'running' => $running]);
    }

    private function remote(string $kind, string $ref, string $commit): void
    {
        $this->agent->shouldReceive('call')->with('deploy.remote_ref', ['domain' => 'app.example.com', 'kind' => $kind])
            ->andReturn(['ref' => $ref, 'commit' => $commit]);
    }

    public function test_a_new_commit_on_the_branch_is_deployed_once(): void
    {
        $this->remote('branch', 'main', 'bbbb');
        $this->history([['id' => 'r1', 'status' => 'success', 'commit' => 'aaaa']]);
        $this->agent->shouldReceive('call')->once()->with('deploy.run', ['domain' => 'app.example.com'])->andReturn(['job' => 'j']);

        $this->assertSame('deployed', app(DeployPoller::class)->poll($this->site));
        $this->assertNull(app(DeployPoller::class)->poll($this->site->refresh()));   // same commit next minute
        $this->assertSame('bbbb', $this->site->refresh()->deploy_polled_ref);
        $this->assertNotNull($this->site->deploy_polled_at);
    }

    public function test_nothing_happens_when_the_last_deploy_already_took_the_commit(): void
    {
        $this->remote('branch', 'main', 'aaaa');
        $this->history([['id' => 'r1', 'status' => 'success', 'commit' => 'aaaa']]);
        $this->agent->shouldNotReceive('call')->with('deploy.run', Mockery::any());

        $this->assertNull(app(DeployPoller::class)->poll($this->site));
    }

    public function test_a_failed_build_is_not_retried_every_minute(): void
    {
        $this->remote('branch', 'main', 'bbbb');
        $this->history([['id' => 'r2', 'status' => 'failed', 'commit' => 'bbbb'], ['id' => 'r1', 'status' => 'success', 'commit' => 'aaaa']]);
        $this->agent->shouldNotReceive('call')->with('deploy.run', Mockery::any());

        $this->assertNull(app(DeployPoller::class)->poll($this->site));
    }

    public function test_a_running_deploy_queues_the_new_commit(): void
    {
        $this->remote('branch', 'main', 'cccc');
        $this->history([['id' => 'r2', 'status' => 'running', 'commit' => 'bbbb']], running: true);
        $this->agent->shouldNotReceive('call')->with('deploy.run', Mockery::any());

        $this->assertSame('queued', app(DeployPoller::class)->poll($this->site));
        $this->assertNotNull($this->site->refresh()->deploy_requested_at);
    }

    public function test_the_newest_tag_is_deployed_by_name(): void
    {
        $this->site->update(['deploy_trigger' => 'poll_tags']);
        $this->remote('tags', 'v1.10.0', 'dddd');
        $this->history([['id' => 'r1', 'status' => 'success', 'ref' => 'v1.9.3', 'commit' => 'cccc']]);
        $this->agent->shouldReceive('call')->once()->with('deploy.run', ['domain' => 'app.example.com', 'ref' => 'v1.10.0'])->andReturn(['job' => 'j']);

        $this->assertSame('deployed', app(DeployPoller::class)->poll($this->site));
        $this->assertSame('v1.10.0', $this->site->refresh()->deploy_polled_ref);
    }

    public function test_a_repository_without_tags_deploys_nothing(): void
    {
        $this->site->update(['deploy_trigger' => 'poll_tags']);
        $this->remote('tags', '', '');
        $this->history([]);
        $this->agent->shouldNotReceive('call')->with('deploy.run', Mockery::any());

        $this->assertNull(app(DeployPoller::class)->poll($this->site));
    }

    public function test_an_unreachable_repository_is_recorded_not_thrown(): void
    {
        $this->agent->shouldReceive('call')->with('deploy.remote_ref', Mockery::any())
            ->andThrow(new AgentException('op_failed', 'git ls-remote failed: Permission denied (publickey)', 'deploy.remote_ref'));

        $this->assertNull(app(DeployPoller::class)->poll($this->site));
        $this->assertStringContainsString('Přidejte deploy klíč', $this->site->refresh()->deploy_poll_error);
    }

    public function test_a_deploy_that_fails_to_start_is_tried_again_next_minute(): void
    {
        $this->remote('branch', 'main', 'bbbb');
        $this->history([['id' => 'r1', 'status' => 'success', 'commit' => 'aaaa']]);
        $this->agent->shouldReceive('call')->with('deploy.run', Mockery::any())
            ->andThrow(new AgentException('busy', 'site is locked', 'deploy.run'));

        $this->assertNull(app(DeployPoller::class)->poll($this->site));
        $this->assertNull($this->site->refresh()->deploy_polled_ref);
        $this->assertStringContainsString('Deploy se nespustil', $this->site->deploy_poll_error);
    }

    public function test_only_sites_watching_the_repository_are_polled(): void
    {
        Site::create(['domain' => 'hook.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active', 'webhook_enabled' => true, 'deploy_trigger' => 'branch']);
        Site::create(['domain' => 'off.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active', 'webhook_enabled' => false, 'deploy_trigger' => 'poll_branch']);
        $this->remote('branch', 'main', 'aaaa');
        $this->history([['id' => 'r1', 'status' => 'success', 'commit' => 'aaaa']]);

        $this->assertSame(0, app(DeployPoller::class)->all());
        $this->agent->shouldNotHaveReceived('call', ['deploy.remote_ref', ['domain' => 'hook.example.com', 'kind' => 'branch']]);
        $this->agent->shouldNotHaveReceived('call', ['deploy.remote_ref', ['domain' => 'off.example.com', 'kind' => 'branch']]);
    }
}
