<?php

namespace Tests\Feature;

use App\Actions\Sites\ManageCiToken;
use App\Actions\Sites\ManageWebhook;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CiDeployTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    private Site $site;

    private string $dir;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wspanel-artifacts-'.uniqid();
        mkdir($this->dir);
        config(['agent.artifact_dir' => $this->dir]);
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('deploy.history', ['domain' => 'app.example.com'])->andReturn([
            'deploys' => [['id' => '20261107T120000Z', 'status' => 'running', 'started' => '2026-11-07T12:00:00Z', 'ref' => 'v1.0.0']],
            'running' => true, 'current' => 'initial', 'releases' => [],
        ])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::create(['domain' => 'app.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active']);
        app(ManageWebhook::class)->enable($this->site);
        $this->token = app(ManageCiToken::class)->generate($this->site);
    }

    private function auth(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    public function test_ci_deploys_a_tag_and_reads_the_status(): void
    {
        $this->agent->shouldReceive('call')->once()->with('deploy.run', ['domain' => 'app.example.com', 'ref' => 'v1.0.0'])->andReturn(['job' => 'j']);

        $res = $this->postJson("/api/deploy/{$this->site->ci_id}", ['ref' => 'v1.0.0'], $this->auth())->assertStatus(202);
        $this->assertSame('20261107T120000Z', $res->json('release'));

        $this->getJson(parse_url($res->json('status_url'), PHP_URL_PATH), $this->auth())
            ->assertOk()->assertJson(['status' => 'running', 'ref' => 'v1.0.0']);
    }

    public function test_wrong_token_or_bad_ref_is_refused(): void
    {
        $this->agent->shouldNotReceive('call')->with('deploy.run', Mockery::any());

        $this->postJson("/api/deploy/{$this->site->ci_id}", ['ref' => 'v1'], $this->auth('nope'))->assertNotFound();
        $this->postJson("/api/deploy/{$this->site->ci_id}", ['ref' => 'v1'])->assertNotFound();
        $this->postJson("/api/deploy/{$this->site->ci_id}", ['ref' => 'v1'], $this->auth($this->site->webhook_secret))->assertNotFound();
        $this->assertNotSame($this->token, $this->site->refresh()->ci_token_hash);
        $this->postJson("/api/deploy/{$this->site->ci_id}", ['ref' => '../x'], $this->auth())->assertUnprocessable();
        $this->postJson("/api/deploy/{$this->site->ci_id}", ['ref' => '--upload-pack=x'], $this->auth())->assertUnprocessable();
    }

    public function test_artifact_is_stored_for_the_agent(): void
    {
        $sent = null;
        $this->agent->shouldReceive('call')->once()->with('deploy.run', Mockery::on(function ($p) use (&$sent) {
            $sent = $p;

            return true;
        }))->andReturn(['job' => 'j']);
        $gz = UploadedFile::fake()->createWithContent('build.tar.gz', gzencode('tar'));

        $this->post("/api/deploy/{$this->site->ci_id}/artifact", ['artifact' => $gz, 'commit' => 'abc1234', 'ref' => 'v1.0.0'], $this->auth())
            ->assertStatus(202);

        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}\.tar\.gz$/', $sent['artifact']);
        $this->assertSame(['abc1234', 'v1.0.0'], [$sent['commit'], $sent['ref']]);
        $this->assertFileExists("{$this->dir}/{$sent['artifact']}");
    }

    public function test_non_gzip_artifact_and_running_deploy(): void
    {
        $this->post("/api/deploy/{$this->site->ci_id}/artifact", ['artifact' => UploadedFile::fake()->createWithContent('x.tar.gz', 'plain')], $this->auth())
            ->assertStatus(422);

        $this->agent->shouldReceive('call')->with('deploy.run', Mockery::any())->andThrow(new AgentException('conflict', 'running', 'deploy.run'));
        $this->postJson("/api/deploy/{$this->site->ci_id}", [], $this->auth())->assertStatus(409);
        $this->assertSame([], glob("{$this->dir}/*"));
    }
}
