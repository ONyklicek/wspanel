<?php

namespace Tests\Feature;

use App\Actions\Sites\DeployTrigger;
use App\Actions\Sites\ManageWebhook;
use App\Filament\Resources\Sites\Pages\DeploySite;
use App\Models\Site;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DeployWebhookTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    private Site $site;

    private bool $running = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('deploy.history', ['domain' => 'app.example.com'])
            ->andReturnUsing(fn () => ['deploys' => [], 'releases' => [], 'current' => 'initial', 'running' => $this->running, 'config' => ['repo' => 'git@github.com:acme/app.git', 'branch' => 'main', 'migrate' => true, 'assets' => true, 'auto_rollback' => true, 'keep' => 5]])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::create(['domain' => 'app.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active']);
        app(ManageWebhook::class)->enable($this->site);
    }

    /** @param array<string, string> $headers */
    private function hook(array $payload, array $headers, ?string $id = null): TestResponse
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $this->call('POST', '/hooks/deploy/'.($id ?? $this->site->webhook_id), [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    }

    private function github(array $payload, string $event = 'push', ?string $secret = null): TestResponse
    {
        $sig = 'sha256='.hash_hmac('sha256', json_encode($payload), $secret ?? $this->site->webhook_secret);

        return $this->hook($payload, ['X-GitHub-Event' => $event, 'X-Hub-Signature-256' => $sig]);
    }

    private function push(string $branch = 'main', string $commit = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'): array
    {
        return ['ref' => "refs/heads/{$branch}", 'after' => $commit];
    }

    public function test_github_push_to_the_branch_deploys(): void
    {
        $this->agent->shouldReceive('call')->once()->with('deploy.run', ['domain' => 'app.example.com'])->andReturn(['job_id' => 'x']);

        $this->github($this->push())->assertStatus(202)->assertJson(['result' => 'deployed']);

        $this->assertDatabaseHas('webhook_deliveries', ['site_id' => $this->site->id, 'provider' => 'github', 'result' => 'deployed', 'commit' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678']);
    }

    public function test_invalid_signature_is_rejected_and_nothing_runs(): void
    {
        $this->agent->shouldNotReceive('call');

        $this->github($this->push(), secret: 'wrong-secret')->assertForbidden();
        $this->hook($this->push(), ['X-GitHub-Event' => 'push'])->assertForbidden(); // no signature at all

        $this->assertSame(2, WebhookDelivery::where('result', 'rejected')->count());
    }

    public function test_unknown_disabled_and_malformed_hooks_are_not_found(): void
    {
        $this->agent->shouldNotReceive('call');

        $this->hook($this->push(), [], str_repeat('a', 32))->assertNotFound();
        $this->hook($this->push(), [], 'not-a-hook-id')->assertNotFound();
        app(ManageWebhook::class)->disable($this->site);
        $this->github($this->push())->assertNotFound();
    }

    public function test_gitlab_gitea_and_bitbucket_signatures(): void
    {
        $this->agent->shouldReceive('call')->times(3)->with('deploy.run', ['domain' => 'app.example.com'])->andReturn(['job_id' => 'x']);
        $gitea = $this->push(commit: 'b1b2c3d4e5f60718293a4b5c6d7e8f9012345678');
        $hmac = hash_hmac('sha256', json_encode($gitea), $this->site->webhook_secret);

        $this->hook($this->push(), ['X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => $this->site->webhook_secret])->assertStatus(202);
        $this->hook($gitea, ['X-Gitea-Event' => 'push', 'X-Gitea-Signature' => $hmac])->assertStatus(202);
        $bitbucket = ['push' => ['changes' => [['new' => ['type' => 'branch', 'name' => 'main', 'target' => ['hash' => 'abcdef1234567']]]]]];
        $this->hook($bitbucket, ['X-Event-Key' => 'repo:push', 'X-Hub-Signature' => 'sha256='.hash_hmac('sha256', json_encode($bitbucket), $this->site->webhook_secret)])->assertStatus(202);

        $this->assertSame(['gitlab', 'gitea', 'bitbucket'], WebhookDelivery::orderBy('id')->pluck('provider')->all());
    }

    public function test_legacy_sha1_signatures_are_not_accepted(): void
    {
        $this->agent->shouldNotReceive('call');

        $this->hook($this->push(), ['X-Event-Key' => 'repo:push', 'X-Hub-Signature' => 'sha1='.hash_hmac('sha1', json_encode($this->push()), $this->site->webhook_secret)])
            ->assertForbidden();
    }

    public function test_other_branches_and_pings_do_not_deploy(): void
    {
        $this->agent->shouldNotReceive('call')->with('deploy.run', Mockery::any());

        $this->github($this->push('feature/x'))->assertStatus(202)->assertJson(['result' => 'ignored']);
        $this->github(['zen' => 'hi'], 'ping')->assertOk()->assertJson(['message' => 'ping']);
        $this->github($this->push(), 'issues')->assertStatus(202)->assertJson(['result' => 'ignored']);
    }

    public function test_push_during_a_deploy_is_queued_and_started_later(): void
    {
        $this->running = true;
        $this->agent->shouldNotReceive('call')->with('deploy.run', Mockery::any());
        $this->github($this->push())->assertStatus(202)->assertJson(['result' => 'queued']);
        $this->github($this->push(commit: 'c1b2c3d4e5f60718293a4b5c6d7e8f9012345678'))->assertStatus(202)->assertJson(['result' => 'queued']);
        $this->assertNotNull($this->site->refresh()->deploy_requested_at);

        $this->running = false;
        $this->agent->shouldReceive('call')->once()->with('deploy.run', ['domain' => 'app.example.com'])->andReturn(['job_id' => 'x']);
        $this->assertSame(1, app(DeployTrigger::class)->runQueued()); // two pushes, one follow-up deploy
        $this->assertNull($this->site->refresh()->deploy_requested_at);
    }

    public function test_oversized_payloads_are_refused(): void
    {
        $this->agent->shouldNotReceive('call');

        $this->call('POST', '/hooks/deploy/'.$this->site->webhook_id, [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('x', 1024 * 1024 + 1))
            ->assertStatus(413);
    }

    public function test_requests_are_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->hook($this->push(), [])->assertForbidden();
        }
        $this->hook($this->push(), [])->assertStatus(429);
    }

    public function test_replayed_deliveries_are_ignored_even_with_a_new_delivery_id(): void
    {
        $this->agent->shouldReceive('call')->once()->with('deploy.run', ['domain' => 'app.example.com'])->andReturn(['job_id' => 'x']);
        $sig = 'sha256='.hash_hmac('sha256', json_encode($this->push()), $this->site->webhook_secret);

        $this->hook($this->push(), ['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => $sig, 'X-GitHub-Delivery' => '72d3162e-cc78-11e3-81ab-4c9367dc0958'])
            ->assertStatus(202)->assertJson(['result' => 'deployed']);
        // Same signed body, forged fresh delivery id (the header is not signed).
        $this->hook($this->push(), ['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => $sig, 'X-GitHub-Delivery' => 'aaaaaaaa-0000-0000-0000-000000000001'])
            ->assertOk()->assertJson(['message' => 'duplicate delivery']);
    }

    public function test_an_already_deployed_commit_does_not_redeploy(): void
    {
        $this->agent->shouldReceive('call')->with('deploy.history', ['domain' => 'app.example.com'])->andReturn([
            'deploys' => [['id' => '20261107T100000Z', 'commit' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678', 'status' => 'success']],
            'current' => '20261107T100000Z', 'running' => false,
            'config' => ['repo' => 'git@github.com:acme/app.git', 'branch' => 'main'],
        ]);
        $this->agent->shouldNotReceive('call')->with('deploy.run', Mockery::any());

        $this->github($this->push())->assertStatus(202)->assertJson(['message' => 'commit is already deployed']);
    }

    public function test_agent_errors_are_not_disclosed_to_the_caller(): void
    {
        $this->agent->shouldReceive('call')->with('deploy.run', Mockery::any())
            ->andThrow(new AgentException('op_failed', 'internal path /srv/sites/app.example.com broke', 'deploy.run'));

        $this->github($this->push())->assertStatus(502)->assertExactJson(['result' => 'failed']);
        $this->assertStringContainsString('/srv/sites', WebhookDelivery::latest('id')->value('message'));
    }

    public function test_disabling_the_webhook_cancels_a_queued_deploy(): void
    {
        $this->site->update(['deploy_requested_at' => now()]);
        app(ManageWebhook::class)->disable($this->site);
        $this->assertNull($this->site->refresh()->deploy_requested_at);

        $this->site->update(['deploy_requested_at' => now(), 'webhook_enabled' => false]);
        $this->agent->shouldNotReceive('call')->with('deploy.run', Mockery::any());
        $this->assertSame(0, app(DeployTrigger::class)->runQueued());
    }

    public function test_site_relations(): void
    {
        $this->github($this->push(), 'ping')->assertOk();
        $this->assertSame(1, $this->site->webhookDeliveries()->count());
        $this->assertSame(0, $this->site->cronJobs()->count());
    }

    public function test_secret_is_encrypted_at_rest_and_rotation_changes_url_and_secret(): void
    {
        $raw = DB::table('sites')->where('id', $this->site->id)->value('webhook_secret');
        $this->assertNotSame($this->site->webhook_secret, $raw);
        $this->assertSame(64, strlen($this->site->webhook_secret));

        $oldId = $this->site->webhook_id;
        $oldSecret = $this->site->webhook_secret;
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->with('deploy.log', Mockery::any())->andReturn(['content' => '', 'offset' => 0, 'status' => 'success']);
        Livewire::test(DeploySite::class, ['record' => $this->site->id])
            ->mountAction('webhook')
            ->callAction('rotateWebhook');

        $this->site->refresh();
        $this->assertNotSame($oldId, $this->site->webhook_id);
        $this->assertNotSame($oldSecret, $this->site->webhook_secret);
        $this->hook($this->push(), [], $oldId)->assertNotFound();
    }

    public function test_release_mode_deploys_the_published_tag_once(): void
    {
        $this->site->update(['deploy_trigger' => 'tags']);
        $this->agent->shouldReceive('call')->once()->with('deploy.run', ['domain' => 'app.example.com', 'ref' => 'v1.4.0'])->andReturn(['job' => 'j1']);

        $this->github(['ref' => 'refs/heads/main', 'after' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'])
            ->assertStatus(202)->assertJson(['result' => 'ignored']);
        $this->github(['action' => 'published', 'release' => ['tag_name' => 'v1.4.0']], 'release')
            ->assertStatus(202)->assertJson(['result' => 'deployed']);

        // The tag push GitHub sends with the release must not deploy it again.
        $this->agent->shouldReceive('call')->with('deploy.history', ['domain' => 'app.example.com'])
            ->andReturn(['deploys' => [['id' => '20261107T120000Z', 'status' => 'running', 'ref' => 'v1.4.0']], 'running' => true, 'current' => 'initial', 'config' => ['branch' => 'main']]);
        $this->github(['ref' => 'refs/tags/v1.4.0', 'after' => 'b1b2c3d4e5f60718293a4b5c6d7e8f9012345678'])
            ->assertStatus(202)->assertJson(['result' => 'ignored']);
    }

    public function test_draft_releases_and_bad_tags_do_not_deploy(): void
    {
        $this->site->update(['deploy_trigger' => 'tags']);
        $this->agent->shouldNotReceive('call')->with('deploy.run', Mockery::any());

        $this->github(['action' => 'created', 'release' => ['tag_name' => 'v2']], 'release')->assertJson(['result' => 'ignored']);
        $this->github(['action' => 'published', 'release' => ['tag_name' => '--upload-pack=x']], 'release')->assertJson(['result' => 'ignored']);
    }
}
