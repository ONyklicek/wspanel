<?php

namespace Tests\Feature;

use App\Actions\Sites\ManageGitHub;
use App\Actions\Sites\ReportGitHubDeployments;
use App\Livewire\Sites\SiteDeploy;
use App\Models\Deployment;
use App\Models\GithubConnection;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\GitHub\GitHubException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class GitHubDeployTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private const API = 'https://api.github.com';

    private MockInterface $agent;

    private Site $site;

    private User $user;

    /** @var array<string, mixed> */
    private array $configSent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://panel.example.com']);
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('deploy.key', ['domain' => 'app.example.com'])->andReturn(['public_key' => "ssh-ed25519 AAAAC3Nza wspanel\n"])->byDefault();
        $this->agent->shouldReceive('call')->with('deploy.history', ['domain' => 'app.example.com'])->andReturn([
            'deploys' => [], 'releases' => [], 'current' => '', 'running' => false,
            'config' => ['repo' => '', 'branch' => 'main', 'migrate' => true, 'assets' => false, 'auto_rollback' => true, 'keep' => 5, 'commands' => ['install:permissions']],
        ])->byDefault();
        $this->agent->shouldReceive('call')->with('deploy.config', Mockery::on(function (array $p) {
            $this->configSent = $p;

            return true;
        }))->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::create(['domain' => 'app.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active']);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function fakeGitHub(array $extra = []): void
    {
        Http::fake($extra + [
            self::API.'/user' => Http::response(['login' => 'acme-dev', 'name' => 'Acme']),
            self::API.'/repos/acme/app' => Http::response(['default_branch' => 'main', 'private' => true, 'ssh_url' => 'git@github.com:acme/app.git']),
            self::API.'/repos/acme/app/keys' => Http::response(['id' => 11], 201),
            self::API.'/repos/acme/app/hooks' => Http::response(['id' => 22], 201),
            self::API.'/repos/acme/app/keys/*' => Http::response(null, 204),
            self::API.'/repos/acme/app/hooks/*' => Http::response(null, 204),
            self::API.'/repos/acme/app/actions/secrets/public-key' => Http::response(['key_id' => 'k1', 'key' => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair()))]),
            self::API.'/repos/acme/app/actions/secrets/WSPANEL_TOKEN' => Http::response(null, 201),
        ]);
    }

    private function connection(?User $user = null): GithubConnection
    {
        return GithubConnection::query()->withoutGlobalScope('owner')->create(['user_id' => ($user ?? $this->user)->id, 'login' => 'acme-dev', 'token' => 'github_pat_secret']);
    }

    public function test_connecting_checks_the_token_and_stores_it_encrypted(): void
    {
        $this->fakeGitHub();

        $c = app(ManageGitHub::class)->connect($this->user, ' github_pat_secret ');

        $this->assertSame('acme-dev', $c->login);
        $this->assertSame('github_pat_secret', $c->fresh()->token);
        $this->assertStringNotContainsString('github_pat_secret', (string) DB::table('github_connections')->value('token'));
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/user' && $r->hasHeader('Authorization', 'Bearer github_pat_secret'));
    }

    public function test_a_rejected_token_is_not_stored(): void
    {
        Http::fake([self::API.'/user' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->expectException(GitHubException::class);
        try {
            app(ManageGitHub::class)->connect($this->user, 'github_pat_revoked');
        } finally {
            $this->assertSame(0, GithubConnection::query()->count());
        }
    }

    public function test_linking_adds_a_read_only_key_configures_the_deploy_and_creates_a_signed_webhook(): void
    {
        $this->fakeGitHub();

        $out = app(ManageGitHub::class)->link($this->site, $this->connection(), ['repo' => 'acme/app', 'branch' => 'release', 'trigger' => 'branch', 'notify' => 'webhook']);

        $site = $this->site->fresh();
        $this->assertSame('branch', $out['trigger']);
        $this->assertSame(['acme/app', 11, 22, 'branch'], [$site->github_repo, $site->github_key_id, $site->github_hook_id, $site->deploy_trigger]);
        $this->assertTrue($site->webhook_enabled);
        $this->assertSame('git@github.com:acme/app.git', $this->configSent['repo']);
        $this->assertSame('release', $this->configSent['branch']);
        $this->assertSame(['install:permissions'], $this->configSent['commands'], 'the rest of the deploy settings stays');
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/repos/acme/app/keys' && $r['read_only'] === true && $r['key'] === 'ssh-ed25519 AAAAC3Nza wspanel');
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/repos/acme/app/hooks'
            && $r['events'] === ['push']
            && $r['config']['url'] === 'https://panel.example.com/hooks/deploy/'.$site->webhook_id
            && $r['config']['secret'] === $site->webhook_secret
            && $r['config']['content_type'] === 'json');
    }

    public function test_a_panel_github_cannot_reach_watches_the_repository_instead(): void
    {
        $this->fakeGitHub();

        app(ManageGitHub::class)->link($this->site, $this->connection(), ['repo' => 'acme/app', 'branch' => 'main', 'trigger' => 'tags', 'notify' => 'poll']);

        $site = $this->site->fresh();
        $this->assertSame(['poll_tags', null], [$site->deploy_trigger, $site->github_hook_id]);
        $this->assertTrue($site->webhook_enabled, 'the poller watches enabled sites only');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/hooks'));
    }

    public function test_the_ci_token_can_be_stored_as_a_sealed_actions_secret(): void
    {
        $this->fakeGitHub();

        app(ManageGitHub::class)->link($this->site, $this->connection(), ['repo' => 'acme/app', 'branch' => 'main', 'trigger' => 'branch', 'notify' => 'poll', 'ci_secret' => true]);

        $this->assertNotNull($this->site->fresh()->ci_token_hash);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/actions/secrets/WSPANEL_TOKEN')
            && $r['key_id'] === 'k1' && ! str_contains((string) base64_decode($r['encrypted_value']), 'wsp_'));
    }

    public function test_a_failed_link_removes_what_it_created_on_github(): void
    {
        $this->fakeGitHub([self::API.'/repos/acme/app/hooks' => Http::response(['message' => 'Resource not accessible by personal access token'], 403)]);

        try {
            app(ManageGitHub::class)->link($this->site, $this->connection(), ['repo' => 'acme/app', 'branch' => 'main', 'trigger' => 'branch', 'notify' => 'webhook']);
            $this->fail('the link must fail');
        } catch (GitHubException $e) {
            $this->assertStringContainsString('Webhooks', $e->getMessage());
        }

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::API.'/repos/acme/app/keys/11');
        $this->assertNull($this->site->fresh()->github_repo);
    }

    public function test_unlinking_removes_the_webhook_and_key_and_stops_webhook_deploys(): void
    {
        $this->fakeGitHub();
        app(ManageGitHub::class)->link($this->site, $this->connection(), ['repo' => 'acme/app', 'branch' => 'main', 'trigger' => 'branch', 'notify' => 'webhook']);

        app(ManageGitHub::class)->unlink($this->site->fresh());

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::API.'/repos/acme/app/hooks/22');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::API.'/repos/acme/app/keys/11');
        $site = $this->site->fresh();
        $this->assertNull($site->github_repo);
        $this->assertFalse($site->webhook_enabled);
    }

    public function test_disconnecting_the_account_unlinks_its_sites(): void
    {
        $this->fakeGitHub();
        $c = $this->connection();
        app(ManageGitHub::class)->link($this->site, $c, ['repo' => 'acme/app', 'branch' => 'main', 'trigger' => 'branch', 'notify' => 'poll']);

        app(ManageGitHub::class)->disconnect($c);

        $this->assertNull($this->site->fresh()->github_repo);
        $this->assertSame(0, GithubConnection::query()->count());
    }

    public function test_deploys_are_reported_to_github_once_per_state(): void
    {
        $this->fakeGitHub([
            self::API.'/repos/acme/app/deployments' => Http::response(['id' => 77], 201),
            self::API.'/repos/acme/app/deployments/77/statuses' => Http::response([], 201),
        ]);
        $this->site->update(['github_connection_id' => $this->connection()->id, 'github_repo' => 'acme/app', 'github_environment' => 'production', 'github_linked_at' => now()->subHour()]);
        Deployment::create(['site_id' => $this->site->id, 'release' => 'old', 'status' => 'success', 'commit' => 'aaa', 'started_at' => now()->subDay()]);
        $d = Deployment::create(['site_id' => $this->site->id, 'release' => 'r1', 'status' => 'success', 'commit' => str_repeat('c', 40), 'ref' => 'v1.2.0', 'started_at' => now()]);
        $report = app(ReportGitHubDeployments::class);

        $this->assertSame(1, $report->site($this->site->fresh()));
        $this->assertSame(0, $report->site($this->site->fresh()), 'nothing new to say');

        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/repos/acme/app/deployments' && $r['ref'] === str_repeat('c', 40)
            && $r['environment'] === 'production' && $r['required_contexts'] === [] && $r['auto_merge'] === false);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/deployments/77/statuses') && $r['state'] === 'success'
            && $r['environment_url'] === 'https://app.example.com/' && str_ends_with($r['log_url'], '/sites/'.$this->site->id.'/deploy'));
        $this->assertSame([77, 'success'], [$d->fresh()->github_deployment_id, $d->fresh()->github_status]);
        Http::assertSentCount(2); // the deploy from before linking is not reported
    }

    public function test_a_commit_github_does_not_know_is_skipped_for_good(): void
    {
        $this->fakeGitHub([self::API.'/repos/acme/app/deployments' => Http::response(['message' => 'No ref found for: bbbb'], 422)]);
        $this->site->update(['github_connection_id' => $this->connection()->id, 'github_repo' => 'acme/app', 'github_linked_at' => now()->subHour()]);
        $d = Deployment::create(['site_id' => $this->site->id, 'release' => 'r1', 'status' => 'success', 'commit' => 'bbbb', 'started_at' => now()]);

        app(ReportGitHubDeployments::class)->site($this->site->fresh());
        app(ReportGitHubDeployments::class)->site($this->site->fresh());

        $this->assertSame('skipped', $d->fresh()->github_status);
        Http::assertSentCount(1);
    }

    public function test_another_users_account_cannot_be_used(): void
    {
        $this->fakeGitHub();
        $foreign = $this->connection(User::factory()->create());

        $this->expectException(ModelNotFoundException::class);
        GithubConnection::query()->findOrFail($foreign->id);
    }

    public function test_the_deploy_page_links_the_repository(): void
    {
        $this->fakeGitHub([self::API.'/user/repos*' => Http::response([['full_name' => 'acme/app', 'private' => true, 'permissions' => ['admin' => true]]])]);
        $c = $this->connection();

        $this->pageAction(Livewire::test(SiteDeploy::class, ['record' => $this->site->id]), 'github', [
            'connection' => $c->id, 'repo' => 'acme/app', 'branch' => 'main', 'trigger' => 'branch', 'notify' => 'webhook', 'ci_secret' => false,
        ])->assertHasNoErrors();

        $this->assertSame('acme/app', $this->site->fresh()->github_repo);
    }

    public function test_the_accounts_page_lists_only_my_github_accounts(): void
    {
        $this->connection();
        GithubConnection::query()->withoutGlobalScope('owner')->create(['user_id' => User::factory()->create()->id, 'login' => 'someone-else', 'token' => 'github_pat_x']);

        $this->get('/api-tokens')->assertOk()->assertSee('acme-dev')->assertDontSee('someone-else')->assertDontSee('github_pat_secret');
    }

    public function test_a_local_panel_is_not_public(): void
    {
        foreach (['https://panel.localhost:8443' => false, 'https://10.0.0.5' => false, 'https://panel.firma.lan' => false, 'https://panel.example.com' => true] as $url => $public) {
            config(['app.url' => $url]);
            $this->assertSame($public, SiteDeploy::panelIsPublic(), $url);
        }
    }
}
