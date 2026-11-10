<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Client;
use App\Models\GameServer;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/** REST API v1: game servers and game packages (docs/api.md). */
class ApiGamesTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    private const FOLIA = '{"id":"minecraft-folia","name":"Folia pro Minecraft","version":"1.0.0","extends":"minecraft","flavors":[{"id":"folia","label":"Folia","source":{"type":"papermc","project":"folia"}}]}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        Features::setGames(true);
    }

    /** @return array<string, string> */
    private function bearer(User $user, bool $write = false): array
    {
        return ['Authorization' => 'Bearer '.ApiToken::issue($user, 't', $write, null)[1]];
    }

    private function server(string $name, ?Client $owner = null, int $port = 25565): GameServer
    {
        $s = new GameServer(['name' => $name, 'game' => 'minecraft', 'flavor' => 'paper', 'version' => '1.21.10', 'port' => $port, 'memory_mb' => 2048, 'status' => 'active']);
        $s->client_id = $owner?->id;
        $s->save();

        return $s;
    }

    public function test_games_list_the_variants_and_their_versions(): void
    {
        $this->agent->shouldReceive('call')->with('games.catalog')->andReturn([
            'minecraft' => ['paper' => ['1.21.10'], 'folia' => ['1.21.8']],
            'games' => ['minecraft' => ['name' => 'Minecraft Java', 'engine' => 'minecraft', 'proto' => 'tcp', 'default_port' => 25565, 'min_memory_mb' => 1024, 'available' => true,
                'flavors' => [['id' => 'paper', 'label' => 'Paper', 'package' => 'minecraft', 'addons' => null], ['id' => 'folia', 'label' => 'Folia', 'package' => 'minecraft-folia', 'addons' => null]]]],
        ]);

        $this->getJson('/api/v1/games', $this->bearer(User::factory()->create()))->assertOk()
            ->assertJsonPath('data.0.id', 'minecraft')->assertJsonPath('data.0.flavors.1.id', 'folia')
            ->assertJsonPath('data.0.flavors.1.package', 'minecraft-folia')->assertJsonPath('data.0.flavors.1.versions', ['1.21.8']);
    }

    public function test_a_client_sees_only_its_own_game_servers(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $this->server('Alice svět', $alice);
        $bobs = $this->server('Bob svět', $bob, 25566);
        $h = $this->bearer(User::factory()->forClient($alice)->create());

        $this->getJson('/api/v1/game-servers', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alice svět');
        $this->getJson('/api/v1/game-servers/'.$bobs->id, $h)->assertNotFound();
    }

    public function test_packages_are_the_administrators_and_need_the_module(): void
    {
        $client = User::factory()->forClient(Client::factory()->create())->create();
        $this->getJson('/api/v1/game-packages', $this->bearer($client))->assertNotFound();
        Features::setGames(false);
        $this->getJson('/api/v1/game-packages', $this->bearer(User::factory()->create()))->assertNotFound();
        $this->getJson('/api/v1/game-servers', $this->bearer(User::factory()->create()))->assertNotFound();
    }

    public function test_an_administrator_lists_uploads_installs_and_removes_packages(): void
    {
        $admin = User::factory()->create();
        $this->agent->shouldReceive('call')->with('games.packages', [])->andReturn(['packages' => [['id' => 'minecraft', 'origin' => 'builtin']], 'problems' => []]);
        $this->agent->shouldReceive('call')->with('games.packages', ['catalog' => true])->andReturn(['packages' => [], 'problems' => [], 'catalog' => [['id' => 'minecraft-folia', 'installed' => '']]]);
        $this->agent->shouldReceive('call')->once()->with('games.package_upload', ['manifest' => self::FOLIA])->andReturn(['installed' => ['id' => 'minecraft-folia']]);
        $this->agent->shouldReceive('call')->once()->with('games.package_install', ['id' => 'minecraft-folia'])->andReturn(['installed' => ['id' => 'minecraft-folia']]);
        $this->agent->shouldReceive('call')->once()->with('games.package_remove', ['id' => 'minecraft-folia'])
            ->andThrow(new AgentException('conflict', 'game servers [9] run Folia pro Minecraft: delete them first', 'games.package_remove'));

        $this->getJson('/api/v1/game-packages', $this->bearer($admin))->assertOk()->assertJsonPath('data.0.id', 'minecraft');
        $this->getJson('/api/v1/game-packages/catalog', $this->bearer($admin))->assertOk()->assertJsonPath('data.0.id', 'minecraft-folia');

        // Changing needs a token that may write; the body is the manifest itself.
        $this->call('POST', '/api/v1/game-packages', [], [], [], ['HTTP_AUTHORIZATION' => $this->bearer($admin)['Authorization'], 'CONTENT_TYPE' => 'application/json'], self::FOLIA)->assertForbidden();
        $w = $this->bearer($admin, true)['Authorization'];
        $this->call('POST', '/api/v1/game-packages', [], [], [], ['HTTP_AUTHORIZATION' => $w, 'CONTENT_TYPE' => 'application/json'], 'not json')->assertStatus(422);
        $this->call('POST', '/api/v1/game-packages', [], [], [], ['HTTP_AUTHORIZATION' => $w, 'CONTENT_TYPE' => 'application/json'], self::FOLIA)->assertCreated()->assertJsonPath('data.id', 'minecraft-folia');
        $this->postJson('/api/v1/game-packages/minecraft-folia/install', [], ['Authorization' => $w])->assertCreated();
        $this->deleteJson('/api/v1/game-packages/minecraft-folia', [], ['Authorization' => $w])->assertStatus(409)->assertJsonPath('error', 'Balíček Folia pro Minecraft používají herní servery (#9): nejdřív je smažte.');
    }
}
