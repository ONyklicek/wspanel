<?php

namespace Tests\Feature;

use App\Enums\Game;
use App\Livewire\Pages\GamePackagesPage;
use App\Models\Client;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Support\Features;
use App\Wire\Resources\GameServerResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

/** Server → Herní balíčky: which games and variants game servers can run. */
class GamePackagesTest extends TestCase
{
    use RefreshDatabase, RunsWireActions;

    private MockInterface $agent;

    private const FOLIA = ['id' => 'minecraft-folia', 'name' => 'Folia pro Minecraft', 'version' => '1.0.0', 'description' => 'Vícevláknový server.',
        'origin' => 'catalog', 'extends' => 'minecraft', 'flavors' => [['id' => 'folia', 'label' => 'Folia (vícevláknový)']], 'servers' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->agent->shouldReceive('call')->with('games.packages', Mockery::any())->andReturn([
            'packages' => [
                ['id' => 'minecraft', 'name' => 'Minecraft Java', 'version' => '1.0.0', 'description' => '', 'origin' => 'builtin', 'game' => 'minecraft', 'game_label' => 'Minecraft Java', 'extends' => '',
                    'flavors' => [['id' => 'paper', 'label' => 'Paper']], 'servers' => [3]],
            ],
            'problems' => [['origin' => 'local', 'file' => 'broken.json', 'error' => 'game.engine: factorio or minecraft']],
            'catalog' => [self::FOLIA + ['installed' => '']],
        ])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        Features::setGames(true);
        $this->actingAs(User::factory()->create());
    }

    public function test_only_an_administrator_with_the_module_on_manages_packages(): void
    {
        $this->get('/game-packages')->assertOk();
        Features::setGames(false);
        $this->get('/game-packages')->assertForbidden();
        Features::setGames(true);
        $this->actingAs(User::factory()->forClient(Client::create(['name' => 'Alice']))->create());
        $this->get('/game-packages')->assertForbidden();
    }

    public function test_installed_packages_their_problems_and_the_catalog_are_shown(): void
    {
        Livewire::test(GamePackagesPage::class)->call('loadCatalog')
            ->assertSee('Minecraft Java')->assertSee('vestavěný')->assertSee('1 server ho používá')
            ->assertSee('broken.json')->assertSee('game.engine: factorio or minecraft')
            ->assertSee('Folia pro Minecraft')->assertSee('Rozšiřuje Minecraft Java')->assertSee('Nainstalovat');
    }

    public function test_a_catalog_package_is_installed_and_the_games_are_asked_again(): void
    {
        $this->agent->shouldReceive('call')->once()->with('games.package_install', ['id' => 'minecraft-folia'])->andReturn(['installed' => self::FOLIA]);
        cache()->put('games.catalog', ['games' => []], 600);

        Livewire::test(GamePackagesPage::class)->call('install', 'minecraft-folia')->assertNotified('Balíček minecraft-folia nainstalován');

        $this->assertNotEquals(['games' => []], cache()->get('games.catalog'), 'the new variants are offered at once (the catalog asked again)');
    }

    public function test_an_own_package_is_uploaded_as_its_manifest(): void
    {
        $manifest = json_encode(['id' => 'minecraft-purpur', 'name' => 'Purpur', 'version' => '1.0.0', 'extends' => 'minecraft', 'flavors' => []]);
        $this->agent->shouldReceive('call')->once()->with('games.package_upload', ['manifest' => $manifest])->andReturn(['installed' => ['name' => 'Purpur']]);

        $this->pageAction(Livewire::test(GamePackagesPage::class), 'upload', ['manifest' => 'not json'])->assertHasErrors();
        $this->pageAction(Livewire::test(GamePackagesPage::class), 'upload', ['manifest' => $manifest])->assertHasNoErrors()->assertNotified('Balíček Purpur nahrán');
    }

    public function test_the_create_form_offers_what_the_packages_add(): void
    {
        $this->agent->shouldReceive('call')->with('games.catalog')->andReturn([
            'minecraft' => ['paper' => ['1.21.10'], 'folia' => ['1.21.8']],
            'games' => ['minecraft' => ['name' => 'Minecraft Java', 'engine' => 'minecraft', 'proto' => 'tcp', 'default_port' => 25565, 'min_memory_mb' => 1024, 'available' => true, 'package' => 'minecraft',
                'flavors' => [
                    ['id' => 'paper', 'label' => 'Paper', 'package' => 'minecraft', 'addons' => ['kind' => 'plugins', 'modrinth_loaders' => ['paper'], 'hangar' => true]],
                    ['id' => 'folia', 'label' => 'Folia (vícevláknový)', 'package' => 'minecraft-folia', 'addons' => ['kind' => 'plugins', 'modrinth_loaders' => ['folia']]],
                ]]],
        ]);

        $this->assertSame(['paper' => 'Paper', 'folia' => 'Folia (vícevláknový)'], Game::from('minecraft')->flavors());
        $this->assertSame('minecraft-folia', Game::from('minecraft')->flavorPackage('folia'));
        $this->assertFalse((bool) (Game::from('minecraft')->addons('folia')['hangar'] ?? false), 'Folia plugins only from Modrinth');
        $this->assertSame(['minecraft' => 'Minecraft Java'], Game::options(), 'a game no package adds any more is not offered');
        $this->assertNotEmpty(GameServerResource::createFields());
    }
}
