<?php

namespace Tests\Feature;

use App\Livewire\Games\GamePlayers;
use App\Livewire\Games\GamePlugins;
use App\Livewire\Games\GameServerSettings;
use App\Livewire\Games\ListGameServers;
use App\Livewire\Games\ViewGameServer;
use App\Livewire\Pages\ModulesPage;
use App\Livewire\Sites\FileBrowser;
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
            'minecraft' => ['paper' => ['26.3', '1.21.10'], 'vanilla' => ['26.3'], 'fabric' => ['26.3'], 'forge' => ['1.21.10']],
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

    public function test_the_files_tab_works_as_the_game_user(): void
    {
        Features::setGames(true);
        $server = GameServer::create(['name' => 'S', 'game' => 'minecraft', 'flavor' => 'paper', 'version' => '1.21.10', 'port' => 25565, 'memory_mb' => 2048, 'status' => 'active']);
        $this->agent->shouldReceive('call')->with('game.files', ['game' => $server->id, 'action' => 'list', 'path' => '.'])
            ->andReturn(['entries' => [['name' => 'data', 'type' => 'dir', 'size' => 0, 'mtime' => 0, 'mode' => '0750'], ['name' => 'server', 'type' => 'dir', 'size' => 0, 'mtime' => 0, 'mode' => '0750']]]);
        $this->agent->shouldNotReceive('call')->with('site.files', Mockery::any());

        $this->get('/games/'.$server->id.'/files')->assertOk()->assertSee('wsg_'.$server->id);
        Livewire::test(FileBrowser::class, ['game' => $server->id])->assertSee('data')->assertSee('server')->assertSee('S');
    }

    public function test_a_client_cannot_download_another_clients_game_files(): void
    {
        Features::setClients(true);
        Features::setGames(true);
        $other = GameServer::create(['name' => 'Theirs', 'game' => 'factorio', 'flavor' => 'stable', 'version' => '2.1.24', 'port' => 34197, 'memory_mb' => 2048, 'status' => 'active']);
        $other->forceFill(['client_id' => Client::factory()->create()->id])->save();
        $this->actingAs(User::factory()->forClient(Client::factory()->create())->create());
        $this->agent->shouldNotReceive('call')->with('game.files', Mockery::any());

        $this->get('/games/'.$other->id.'/files/download?path=data/server-settings.json')->assertNotFound();
    }

    public function test_a_clients_server_gets_a_cpu_quota_within_the_package(): void
    {
        Features::setClients(true);
        Features::setGames(true);
        $package = Package::create(['name' => 'Hry', 'max_game_cpu_percent' => 100]);
        $client = Client::factory()->create(['package_id' => $package->id, 'linux_group' => 'wsc_5']);
        $this->actingAs(User::factory()->forClient($client)->create());
        $this->agent->shouldReceive('call')->once()->with('game.create', Mockery::on(fn ($p) => $p['cpu_percent'] === 100))->andReturn([]);

        $form = ['name' => 'A', 'game' => 'factorio', 'flavor' => 'stable', 'version' => '2.1.24', 'memory_mb' => 2048];
        foreach ([0, 200] as $cpu) {
            $this->headerTableAction(Livewire::test(ListGameServers::class), 'create', [...$form, 'cpu_percent' => $cpu])->assertNotified();
        }
        $this->assertSame(0, GameServer::count(), 'no limit, or more than the package allows');
        $this->headerTableAction(Livewire::test(ListGameServers::class), 'create', [...$form, 'cpu_percent' => 100]);
        $this->assertSame(1, GameServer::count());
        $this->headerTableAction(Livewire::test(ListGameServers::class), 'create', [...$form, 'cpu_percent' => 100, 'port' => 27500])->assertHasErrors();
    }

    private function minecraft(): GameServer
    {
        Features::setGames(true);

        return GameServer::create(['name' => 'S', 'game' => 'minecraft', 'flavor' => 'paper', 'version' => '1.21.10', 'port' => 25565, 'memory_mb' => 2048, 'status' => 'active']);
    }

    public function test_the_settings_form_sends_only_what_changed_and_checks_it(): void
    {
        $server = $this->minecraft();
        $this->agent->shouldReceive('call')->with('game.properties', ['id' => $server->id])->andReturn(['exists' => true, 'properties' => [
            ['key' => 'motd', 'value' => 'Hi'], ['key' => 'difficulty', 'value' => 'easy'], ['key' => 'max-players', 'value' => '20'],
            ['key' => 'server-port', 'value' => '25565', 'panel' => true], ['key' => 'rcon.password', 'value' => '', 'panel' => true],
            ['key' => 'some-new-key', 'value' => 'x'],
        ]]);
        $this->agent->shouldReceive('call')->once()->with('game.set_properties', ['id' => $server->id, 'values' => ['difficulty' => 'hard', 'some-new-key' => 'y'], 'restart' => true])->andReturn([]);

        $page = Livewire::test(GameServerSettings::class, ['record' => $server->id])
            ->assertSee('Obtížnost')->assertSee('Další nastavení')->assertSee('some-new-key')->assertSee('server-port')
            ->assertDontSee('name="values.server-port"', false);
        $this->assertArrayNotHasKey('server-port', $page->get('values'));
        $page->set('values.max-players', '5000')->call('save')->assertHasErrors('values.max-players')
            ->set('values.max-players', '20')->set('values.difficulty', 'brutal')->call('save')->assertHasErrors('values.difficulty')
            ->set('values.difficulty', 'hard')->set('values.some-new-key', 'y')->call('save', true)->assertHasNoErrors()->assertNotified();
    }

    public function test_players_are_changed_through_the_console(): void
    {
        $server = $this->minecraft();
        $this->agent->shouldReceive('call')->with('game.player_lists', ['id' => $server->id])->andReturn(['whitelist_on' => true, 'whitelist' => [['name' => 'Notch', 'uuid' => 'x']], 'ops' => [], 'banned' => [], 'banned_ips' => []]);
        $this->agent->shouldReceive('call')->with('game.status', ['id' => $server->id])->andReturn(['state' => 'active', 'players' => ['online' => 1, 'names' => ['Alice']]]);
        $this->agent->shouldReceive('call')->once()->with('game.player_action', ['id' => $server->id, 'action' => 'whitelist_add', 'name' => 'Alice'])->andReturn(['output' => 'Added Alice to the whitelist']);
        $this->agent->shouldReceive('call')->once()->with('game.player_action', ['id' => $server->id, 'action' => 'ban', 'name' => 'Griefer', 'reason' => 'spam'])->andReturn(['output' => '']);
        $this->agent->shouldReceive('call')->once()->with('game.player_action', ['id' => $server->id, 'action' => 'whitelist_remove', 'name' => 'Notch'])->andReturn(['output' => '']);

        Livewire::test(GamePlayers::class, ['record' => $server->id])
            ->assertSee('Notch')->assertSee('Alice')->assertSee('zapnutý')
            ->set('name', 'a; stop')->call('act', 'op')->assertHasErrors('name')
            ->set('name', 'Alice')->call('act', 'whitelist_add')->assertNotified()
            ->set('name', 'Griefer')->set('reason', 'spam')->call('act', 'ban')
            ->call('act', 'whitelist_remove', 'Notch')
            ->call('act', 'stop');
    }

    public function test_plugins_are_searched_installed_updated_and_removed(): void
    {
        $server = $this->minecraft();
        $this->agent->shouldReceive('call')->with('game.plugins', ['id' => $server->id])->andReturn(['plugins' => [['file' => 'LuckPerms.jar', 'source' => 'modrinth', 'project' => 'Vebnzrzj', 'name' => 'LuckPerms', 'version' => '5.5.70'], ['file' => 'Own.jar']]]);
        $this->agent->shouldReceive('call')->with('game.plugins', ['id' => $server->id, 'check' => true])->andReturn(['plugins' => [['file' => 'LuckPerms.jar', 'source' => 'modrinth', 'project' => 'Vebnzrzj', 'name' => 'LuckPerms', 'version' => '5.5.70', 'update' => '5.5.71']]]);
        $this->agent->shouldReceive('call')->once()->with('game.plugin_search', ['id' => $server->id, 'source' => 'hangar', 'query' => 'via'])
            ->andReturn(['results' => [['source' => 'hangar', 'project' => 'ViaVersion', 'name' => 'ViaVersion', 'description' => 'versions', 'author' => 'ViaVersion', 'downloads' => 500000, 'url' => 'https://hangar.papermc.io/ViaVersion/ViaVersion']]]);
        $this->agent->shouldReceive('call')->once()->with('game.plugin_install', ['id' => $server->id, 'source' => 'hangar', 'project' => 'ViaVersion'])
            ->andReturn(['installed' => [['name' => 'ViaVersion', 'version' => '5.12.2', 'file' => 'ViaVersion-5.12.2.jar']], 'needs' => []]);
        $this->agent->shouldReceive('call')->once()->with('game.plugin_remove', ['id' => $server->id, 'file' => 'Own.jar'])->andReturn([]);

        Livewire::test(GamePlugins::class, ['record' => $server->id])
            ->assertSee('LuckPerms')->assertSee('nahráno ručně')
            ->call('checkUpdates')->assertSee('nová verze 5.5.71')
            ->set('source', 'hangar')->set('query', 'via')->call('findPlugins')->assertSee('ViaVersion')
            ->call('install', 'hangar', 'ViaVersion')->assertNotified()->assertSee('Změny se projeví po restartu serveru')
            ->call('install', 'spigot', 'x')
            ->call('remove', 'Own.jar');
    }

    public function test_a_vanilla_server_has_no_plugins(): void
    {
        Features::setGames(true);
        $server = GameServer::create(['name' => 'V', 'game' => 'minecraft', 'flavor' => 'vanilla', 'version' => '26.3', 'port' => 25565, 'memory_mb' => 2048, 'status' => 'active']);
        $this->agent->shouldNotReceive('call')->with('game.plugins', Mockery::any());

        Livewire::test(GamePlugins::class, ['record' => $server->id])->assertSee('Pluginy jen pro Paper');
    }

    private function factorio(): GameServer
    {
        Features::setGames(true);

        return GameServer::create(['name' => 'F', 'game' => 'factorio', 'flavor' => 'stable', 'version' => '2.1.24', 'port' => 34197, 'memory_mb' => 2048, 'status' => 'active']);
    }

    public function test_factorios_settings_are_typed_and_secrets_never_shown(): void
    {
        $server = $this->factorio();
        $this->agent->shouldReceive('call')->with('game.factorio_settings', ['id' => $server->id])->andReturn([
            'values' => ['name' => 'F', 'max_players' => 0, 'visibility.public' => false, 'visibility.lan' => true, 'tags' => ['a'], 'allow_commands' => 'admins-only'],
            'secrets' => ['token' => true, 'game_password' => false],
        ]);
        $this->agent->shouldReceive('call')->once()->with('game.factorio_set_settings', Mockery::on(fn ($p) => $p['values'] === ['tags' => ['a', 'b'], 'max_players' => 8, 'visibility.public' => true]
            && $p['secrets'] === ['game_password' => 'heslo'] && ($p['restart'] ?? false) === true))->andReturn([]);

        $page = Livewire::test(GameServerSettings::class, ['record' => $server->id])
            ->assertSee('Ve veřejném seznamu serverů')->assertSee('nastaveno');
        $page->set('factorio.max_players', 'lots')->call('saveFactorio')->assertHasErrors('factorio.max_players')
            ->set('factorio.max_players', '8')->set('factorio.visibility__public', true)->set('factorio.tags', 'a, b')->set('newGamePassword', 'heslo')
            ->call('saveFactorio', true)->assertHasNoErrors()->assertNotified();
    }

    public function test_factorio_mods_need_the_account_and_come_from_the_portal(): void
    {
        $server = $this->factorio();
        $this->agent->shouldReceive('call')->with('game.mods', ['id' => $server->id])->andReturn(['mods' => [['file' => 'flib_0.16.5.zip', 'name' => 'flib', 'version' => '0.16.5']], 'account' => false]);
        $this->agent->shouldReceive('call')->once()->with('game.mod_search', ['id' => $server->id, 'query' => 'even'])
            ->andReturn(['results' => [['name' => 'even-distribution', 'title' => 'Even Distribution', 'owner' => 'Xorimuth', 'summary' => 'items', 'downloads_count' => 1000]]]);
        $this->agent->shouldReceive('call')->once()->with('game.mod_install', ['id' => $server->id, 'name' => 'even-distribution'])->andReturn(['installed' => [['name' => 'even-distribution', 'version' => '2.1.0', 'file' => 'even-distribution_2.1.0.zip']]]);
        $this->agent->shouldReceive('call')->once()->with('game.mod_remove', ['id' => $server->id, 'file' => 'flib_0.16.5.zip'])->andReturn([]);
        $this->agent->shouldNotReceive('call')->with('game.plugin_search', Mockery::any());

        Livewire::test(GamePlugins::class, ['record' => $server->id])
            ->assertSee('flib')->assertSee('jen s účtem factorio.com')
            ->set('query', 'even')->call('findPlugins')->assertSee('Even Distribution')
            ->call('install', 'factorio', 'even-distribution')->assertNotified()
            ->call('install', 'modrinth', 'luckperms')
            ->call('remove', 'flib_0.16.5.zip');
    }

    public function test_sftp_into_the_server_with_a_generated_password(): void
    {
        $server = $this->minecraft();
        $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl me@pc';
        $this->agent->shouldReceive('call')->once()->with('game.sftp', Mockery::on(fn ($p) => $p['enabled'] === true && $p['keys'] === [$key]
            && $p['password_mode'] === 'set' && strlen($p['password']) === 20))->andReturn(['sftp' => true, 'password' => true]);
        $this->agent->shouldReceive('call')->once()->with('game.sftp', Mockery::on(fn ($p) => $p['enabled'] === false))->andReturn(['sftp' => false, 'password' => false]);

        $this->pageAction(Livewire::test(ViewGameServer::class, ['record' => $server->id]), 'sftp', ['enabled' => true, 'password_mode' => 'keep', 'keys' => ''])->assertHasErrors();
        $this->pageAction(Livewire::test(ViewGameServer::class, ['record' => $server->id]), 'sftp', ['enabled' => true, 'password_mode' => 'generate', 'keys' => $key])->assertHasNoErrors();
        $this->assertTrue($server->fresh()->sftp);
        $this->assertTrue($server->fresh()->sftp_password);
        Livewire::test(ViewGameServer::class, ['record' => $server->id])->assertSee('wsg_'.$server->id.'@');
        $this->pageAction(Livewire::test(ViewGameServer::class, ['record' => $server->id]), 'sftp', ['enabled' => false]);
        $this->assertFalse($server->fresh()->sftp);
    }

    public function test_a_client_without_sftp_in_the_package_is_refused(): void
    {
        Features::setClients(true);
        Features::setGames(true);
        $package = Package::create(['name' => 'Bez SSH', 'ssh' => 'off']);
        $client = Client::factory()->create(['package_id' => $package->id, 'linux_group' => 'wsc_5']);
        $server = GameServer::create(['name' => 'S', 'game' => 'factorio', 'flavor' => 'stable', 'version' => '2.1.24', 'port' => 34197, 'memory_mb' => 2048, 'status' => 'active']);
        $server->forceFill(['client_id' => $client->id])->save();
        $this->actingAs(User::factory()->forClient($client)->create());
        $this->agent->shouldNotReceive('call')->with('game.sftp', Mockery::any());

        $this->pageAction(Livewire::test(ViewGameServer::class, ['record' => $server->id]), 'sftp', ['enabled' => true, 'password_mode' => 'generate'])->assertNotified();
        $this->assertFalse($server->fresh()->sftp);
    }

    public function test_a_fabric_server_takes_mods_from_modrinth_only(): void
    {
        Features::setGames(true);
        $this->agent->shouldReceive('call')->once()->with('game.create', Mockery::on(fn ($p) => $p['flavor'] === 'fabric' && $p['version'] === '26.3'))->andReturn(['build' => '0.20.1']);
        $this->headerTableAction(Livewire::test(ListGameServers::class), 'create', ['name' => 'Mody', 'game' => 'minecraft', 'flavor' => 'fabric', 'version' => '26.3', 'memory_mb' => 3072, 'eula' => true])->assertHasNoErrors();
        $server = GameServer::sole();
        $this->assertSame('Fabric 26.3 #0.20.1', $server->versionLabel());

        $this->agent->shouldReceive('call')->with('game.plugins', ['id' => $server->id])->andReturn(['plugins' => []]);
        $this->agent->shouldReceive('call')->once()->with('game.plugin_search', ['id' => $server->id, 'source' => 'modrinth', 'query' => 'sodium'])->andReturn(['results' => []]);
        Livewire::test(GamePlugins::class, ['record' => $server->id])
            ->assertSee('Přidat mód')->assertDontSee('Hangar (PaperMC)')
            ->set('source', 'hangar')->set('query', 'sodium')->call('findPlugins')
            ->call('install', 'hangar', 'ViaVersion');
    }
}
