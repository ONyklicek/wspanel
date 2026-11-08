<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ApiV1Test extends TestCase
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

    private function site(string $domain, ?Client $owner = null): Site
    {
        return Site::acrossClients()->create(['domain' => $domain, 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active', 'client_id' => $owner?->id]);
    }

    /** @return array<string, string> */
    private function bearer(User $user, bool $write = false, ?int $days = null): array
    {
        return ['Authorization' => 'Bearer '.ApiToken::issue($user, 't', $write, $days)[1]];
    }

    public function test_a_clients_token_sees_only_its_own_sites(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $this->site('alice.cz', $alice);
        $this->site('bob.cz', $bob);
        $h = $this->bearer(User::factory()->forClient($alice)->create());

        $this->getJson('/api/v1/sites', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.domain', 'alice.cz');
        $this->getJson('/api/v1/sites/bob.cz', $h)->assertNotFound();
        $this->getJson('/api/v1/me', $h)->assertJsonPath('role', 'client');
    }

    public function test_a_read_only_token_cannot_deploy_and_a_writing_one_can(): void
    {
        $this->site('app.cz');
        $admin = User::factory()->create();
        $this->agent->shouldReceive('call')->once()->with('deploy.run', ['domain' => 'app.cz', 'ref' => 'v2'])->andReturn([]);
        $this->agent->shouldReceive('call')->with('deploy.history', Mockery::any())->andReturn(['deploys' => [['id' => '20261108-1', 'status' => 'running', 'started' => now()->toIso8601String(), 'finished' => null, 'ref' => 'v2', 'commit' => null, 'error' => null]]]);

        $this->postJson('/api/v1/sites/app.cz/deploy', ['ref' => 'v2'], $this->bearer($admin))->assertForbidden();
        $this->postJson('/api/v1/sites/app.cz/deploy', ['ref' => 'v2'], $this->bearer($admin, true))->assertStatus(202)->assertJsonPath('release', '20261108-1');
        $this->postJson('/api/v1/sites/app.cz/deploy', ['ref' => '../etc'], $this->bearer($admin, true))->assertUnprocessable();
    }

    public function test_bad_expired_and_suspended_tokens_are_refused(): void
    {
        $this->getJson('/api/v1/sites', ['Authorization' => 'Bearer wspa_nonsense'])->assertUnauthorized();
        $this->getJson('/api/v1/sites')->assertUnauthorized();

        $admin = User::factory()->create();
        [$token, $plain] = ApiToken::issue($admin, 'old', false, 30);
        $token->forceFill(['expires_at' => now()->subDay()])->save();
        $this->getJson('/api/v1/sites', ['Authorization' => 'Bearer '.$plain])->assertUnauthorized();

        $client = Client::factory()->create(['suspended_at' => now()]);
        $this->getJson('/api/v1/sites', $this->bearer(User::factory()->forClient($client)->create()))->assertUnauthorized();
    }

    public function test_only_the_hash_is_stored_and_each_user_manages_their_own(): void
    {
        $me = User::factory()->create();
        [$token, $plain] = ApiToken::issue($me, 'cli', false, null);
        ApiToken::issue(User::factory()->create(), 'someone else', false, null);

        $this->assertSame(hash('sha256', $plain), $token->fresh()->token_hash);
        $this->actingAs($me)->get('/api-tokens')->assertOk()->assertSee('cli')->assertDontSee('someone else');
    }
}
