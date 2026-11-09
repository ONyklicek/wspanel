<?php

namespace Tests\Feature;

use App\Livewire\Games\ListGameServers;
use App\Livewire\Games\ViewGameServer;
use App\Livewire\Pages\ModulesPage;
use App\Models\Client;
use App\Models\GameServer;
use App\Models\Package;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\Features;
use App\Support\Limits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class GameServersTest extends TestCase
{
    use RefreshDatabase, RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->agent->shouldReceive('call')->with('games.catalog')->andReturn([
            'minecraft' => ['paper' => ['26.3', '1.21.10'], 'vanilla' => ['26.3']],
            'factorio' => ['stable' => ['2.1.24']],
            'games' => [],
        ])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    public function test_the_module_is_off_until_switched_on(): void
    {
        $this->get('/games')->assertForbidden();
        $this->pageAction(Livewire::test(ModulesPage::class), 'enableGames');
        $this->assertTrue(Features::games());
        $this->get('/games')->assertOk();
    }

    public function test_a_minecraft_server_needs_the_eula_and_lands_on_its_page(): void
    {
        Features::setGames(true);
        $this->agent->shouldReceive('call')->once()->with('game.create', Mockery::on(fn ($p) => $p['game'] === 'minecraft' && $p['flavor'] === 'paper'
            && $p['version'] === '1.21.10' && $p['port'] === 25565 && $p['memory_mb'] === 2048 && $p['eula'] === true && ! isset($p['group'])))
            ->andReturn(['build' => '130']);

        $form = ['name' => 'Survival', 'game' => 'minecraft', 'flavor' => 'paper', 'version' => '1.21.10', 'memory_mb' => 2048, 'cpu_percent' => 0];
        $this->headerTableAction(Livewire::test(ListGameServers::class), 'create', [...$form, 'eula' => false])->assertHasErrors();
        $this->assertSame(0, GameServer::count());
        $this->headerTableAction(Livewire::test(ListGameServers::class), 'create', [...$form, 'version' => '1.19.4', 'eula' => true])->assertHasErrors();

        $this->headerTableAction(Livewire::test(ListGameServers::class), 'create', [...$form, 'eula' => true])->assertHasNoErrors();
        $server = GameServer::sole();
        $this->assertSame('active', $server->status);
        $this->assertSame('Paper 1.21.10 #130', $server->versionLabel());
    }

    public function test_a_failed_install_leaves_no_record(): void
    {
        Features::setGames(true);
        $this->agent->shouldReceive('call')->with('game.create', Mockery::any())->andThrow(new AgentException('conflict', 'port 25565/tcp is in use'));

        $this->headerTableAction(Livewire::test(ListGameServers::class), 'create', ['name' => 'F', 'game' => 'factorio', 'flavor' => 'stable', 'version' => '2.1.24', 'memory_mb' => 2048])
            ->assertNotified();
        $this->assertSame(0, GameServer::count());
    }

    public function test_a_client_is_held_to_the_package(): void
    {
        Features::setClients(true);
        Features::setGames(true);
        $package = Package::create(['name' => 'Hry', 'max_game_servers' => 1, 'max_game_memory_mb' => 3000]);
        $client = Client::factory()->create(['package_id' => $package->id, 'linux_group' => 'wsc_5']);
        $this->actingAs(User::factory()->forClient($client)->create());
        $this->agent->shouldReceive('call')->once()->with('game.create', Mockery::on(fn ($p) => $p['group'] === 'wsc_5' && $p['memory_mb'] === 2048))->andReturn([]);

        $form = ['name' => 'A', 'game' => 'factorio', 'flavor' => 'stable', 'version' => '2.1.24'];
        $this->headerTableAction(Livewire::test(ListGameServers::class), 'create', [...$form, 'memory_mb' => 4096])->assertNotified();
        $this->assertSame(0, GameServer::count(), 'more memory than the package allows');
        $this->headerTableAction(Livewire::test(ListGameServers::class), 'create', [...$form, 'memory_mb' => 2048]);
        $this->assertSame($client->id, GameServer::sole()->client_id);
        $this->assertFalse(app(Limits::class)->canAdd($client->fresh(), 'games'), 'one server is the limit of the package');
    }

    public function test_the_page_shows_players_and_runs_console_commands(): void
    {
        Features::setGames(true);
        $server = GameServer::create(['name' => 'Survival', 'game' => 'minecraft', 'flavor' => 'paper', 'version' => '1.21.10', 'build' => '130', 'port' => 25565, 'memory_mb' => 2048, 'status' => 'active']);
        $this->agent->shouldReceive('call')->with('game.status', ['id' => $server->id])->andReturn(['state' => 'active', 'memory_bytes' => 900_000_000, 'players' => ['online' => 1, 'max' => 20, 'names' => ['Alice']]]);
        $this->agent->shouldReceive('call')->once()->with('game.command', ['id' => $server->id, 'command' => 'say ahoj'])->andReturn(['output' => '']);
        $this->agent->shouldReceive('call')->once()->with('game.action', ['id' => $server->id, 'action' => 'stop'])->andReturn([]);

        Livewire::test(ViewGameServer::class, ['record' => $server->id])
            ->assertSee('Alice')->assertSee('1 / 20')
            ->set('command', "say a\nstop")->call('send')->assertHasErrors('command')
            ->set('command', 'say ahoj')->call('send')->assertHasNoErrors()->assertSee('&gt; say ahoj', false)
            ->call('control', 'stop')->assertNotified()
            ->call('control', 'rm -rf');
    }

    public function test_another_clients_server_is_out_of_reach(): void
    {
        Features::setClients(true);
        Features::setGames(true);
        $other = GameServer::create(['name' => 'Theirs', 'game' => 'factorio', 'flavor' => 'stable', 'version' => '2.1.24', 'port' => 34197, 'memory_mb' => 2048, 'status' => 'active']);
        $other->forceFill(['client_id' => Client::factory()->create()->id])->save();
        $this->actingAs(User::factory()->forClient(Client::factory()->create())->create());

        $this->get('/games/'.$other->id)->assertNotFound();
    }

    public function test_deleting_and_restoring(): void
    {
        Features::setGames(true);
        $server = GameServer::create(['name' => 'S', 'game' => 'minecraft', 'flavor' => 'vanilla', 'version' => '26.3', 'port' => 25566, 'memory_mb' => 2048, 'status' => 'active']);
        $this->agent->shouldReceive('call')->once()->with('game.restore', ['id' => $server->id, 'file' => 'game-1-20261109-120000.tar.gz'])->andReturn([]);
        $this->agent->shouldReceive('call')->once()->with('game.delete', ['id' => $server->id])->andReturn([]);

        $page = Livewire::test(ViewGameServer::class, ['record' => $server->id])->call('restore', 'game-1-20261109-120000.tar.gz')->assertNotified();
        $this->pageAction($page, 'delete');
        $this->assertSame(0, GameServer::count());
    }

    public function test_every_server_is_backed_up_at_night(): void
    {
        Features::setGames(true);
        $a = GameServer::create(['name' => 'A', 'game' => 'factorio', 'flavor' => 'stable', 'version' => '2.1.24', 'port' => 34197, 'memory_mb' => 2048, 'status' => 'active']);
        GameServer::create(['name' => 'B', 'game' => 'factorio', 'flavor' => 'stable', 'version' => '2.1.24', 'port' => 34198, 'memory_mb' => 2048, 'status' => 'installing']);
        $this->agent->shouldReceive('call')->once()->with('game.backup', ['id' => $a->id])->andReturn(['file' => 'game-1-20261109T040000Z.tar.gz', 'size' => 5, 'offsite' => 'srv/games/1/x']);

        $this->artisan('panel:game-backups')->assertSuccessful();
    }

    public function test_a_copy_only_off_site_is_fetched_back(): void
    {
        Features::setGames(true);
        $server = GameServer::create(['name' => 'S', 'game' => 'factorio', 'flavor' => 'stable', 'version' => '2.1.24', 'port' => 34197, 'memory_mb' => 2048, 'status' => 'active']);
        $this->agent->shouldReceive('call')->with('game.backups', ['id' => $server->id])->andReturn(['backups' => []]);
        $this->agent->shouldReceive('call')->with('offsite.list', ['game' => $server->id])->andReturn(['objects' => [['file' => 'game-1-20261101T040000Z.tar.gz', 'size' => 10, 'modified' => '2026-11-01T04:00:00Z']]]);
        $this->agent->shouldReceive('call')->once()->with('offsite.fetch', ['game' => $server->id, 'file' => 'game-1-20261101T040000Z.tar.gz'])->andReturn([]);

        Livewire::test(ViewGameServer::class, ['record' => $server->id])
            ->call('loadBackups')->assertSee('Jen mimo server')->assertSee('game-1-20261101T040000Z.tar.gz')
            ->call('fetchOffsite', 'game-1-20261101T040000Z.tar.gz')->assertNotified();
    }
}
