<?php

namespace Tests\Feature;

use App\Livewire\Pages\DatabaseServersPage;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class DatabaseServersTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    /** @var array<string, array<string, mixed>> */
    private array $engines = [
        'mariadb' => ['port' => 3306, 'remote' => false, 'anywhere' => false, 'allow' => [], 'root_user' => 'root', 'root_password' => false, 'version' => '11.8.3'],
        'postgresql' => ['port' => 5432, 'remote' => true, 'anywhere' => false, 'allow' => ['10.8.0.0/24'], 'root_user' => 'postgres', 'root_password' => true, 'root_set_at' => '2026-11-07T10:00:00Z', 'version' => '17.6'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('db.server_status')->andReturnUsing(fn () => ['engines' => $this->engines])->byDefault();
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn(['db_engines' => ['mariadb', 'postgresql'], 'db_tools' => ['phpmyadmin', 'adminer']])->byDefault();
        $this->agent->shouldReceive('call')->with('db.instances')->andReturn(['instances' => []])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    public function test_page_shows_each_server(): void
    {
        $this->get('/db-servers')->assertOk()
            ->assertSee('MariaDB')->assertSee('11.8.3')->assertSee('jen tento server')
            ->assertSee('PostgreSQL')->assertSee('z vybraných adres')->assertSee('10.8.0.0/24')
            ->assertSee('sslmode=require')
            ->assertSee(route('dbtools.server', ['mariadb', 'phpmyadmin']), false)
            ->assertDontSee(route('dbtools.server', ['postgresql', 'phpmyadmin']), false);
    }

    public function test_generated_root_password_is_shown_once(): void
    {
        $this->agent->shouldReceive('call')->once()->with('db.root_password', ['engine' => 'mariadb'])
            ->andReturn(['engine' => 'mariadb', 'user' => 'root', 'root_password' => true, 'password' => 'GeneratedByTheAgent1234567890abc']);

        $this->pageAction(Livewire::test(DatabaseServersPage::class), 'rootPassword_mariadb', ['mode' => 'generate'])
            ->assertHasNoErrors()
            ->assertNotified('heslo (zobrazí se jen teď): GeneratedByTheAgent1234567890abc', 'success');
    }

    public function test_own_root_password_is_validated_before_the_agent(): void
    {
        $this->agent->shouldNotReceive('call')->with('db.root_password', Mockery::any());

        foreach (['short', "with 'quote' and space 123"] as $bad) {
            $this->pageAction(Livewire::test(DatabaseServersPage::class), 'rootPassword_postgresql', ['mode' => 'own', 'password' => $bad])
                ->assertHasErrors();
        }
    }

    public function test_root_password_can_be_removed(): void
    {
        $this->agent->shouldReceive('call')->once()->with('db.root_password', ['engine' => 'postgresql', 'remove' => true])->andReturn([]);

        $this->pageAction(Livewire::test(DatabaseServersPage::class), 'rootPassword_postgresql', ['mode' => 'remove'])
            ->assertHasNoErrors()
            ->assertNotified('Heslo uživatele postgres zrušeno');
    }

    public function test_publishing_to_an_allowlist(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('db.remote', ['engine' => 'mariadb', 'enabled' => true, 'anywhere' => false, 'allow' => ['203.0.113.7', '10.8.0.0/24']])
            ->andReturn(['allow' => ['203.0.113.7/32', '10.8.0.0/24']]);

        $this->pageAction(Livewire::test(DatabaseServersPage::class), 'access_mariadb', ['access' => 'allowlist', 'allow' => "203.0.113.7\n10.8.0.0/24\n\n203.0.113.7"])
            ->assertHasNoErrors()
            ->assertNotified('203.0.113.7/32, 10.8.0.0/24');
    }

    public function test_allowlist_entries_are_checked(): void
    {
        $this->agent->shouldNotReceive('call')->with('db.remote', Mockery::any());

        foreach (['', 'kancelar', '10.0.0.0/33', '0.0.0.0/0'] as $bad) {
            $this->pageAction(Livewire::test(DatabaseServersPage::class), 'access_mariadb', ['access' => 'allowlist', 'allow' => $bad])
                ->assertHasErrors();
        }
    }

    public function test_anywhere_needs_an_explicit_confirmation(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('db.remote', ['engine' => 'postgresql', 'enabled' => true, 'anywhere' => true, 'allow' => []])
            ->andReturn(['allow' => []]);

        $this->pageAction(Livewire::test(DatabaseServersPage::class), 'access_postgresql', ['access' => 'anywhere'])
            ->assertHasErrors();
        $this->pageAction(Livewire::test(DatabaseServersPage::class), 'access_postgresql', ['access' => 'anywhere', 'understood' => true])
            ->assertHasNoErrors()
            ->assertNotified('je dostupná z internetu', 'warning');
    }

    public function test_back_to_local_only(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('db.remote', ['engine' => 'postgresql', 'enabled' => false, 'anywhere' => false, 'allow' => []])
            ->andReturn([]);

        $this->pageAction(Livewire::test(DatabaseServersPage::class), 'access_postgresql', ['access' => 'local'])
            ->assertHasNoErrors()
            ->assertNotified('jen z tohoto serveru');
    }

    public function test_server_wide_tool_signon(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('db.access', ['engine' => 'postgresql', 'tool' => 'adminer', 'scope' => 'all'])
            ->andReturn(['token' => str_repeat('cd', 32), 'signon_path' => '/dbtools/adminer/wspanel-signon.php']);

        $this->get(route('dbtools.server', ['postgresql', 'adminer']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('všechny databáze PostgreSQL')
            ->assertSee('name="token" value="'.str_repeat('cd', 32).'"', false);

        $this->get('/db-open-server/postgresql/phpmyadmin')->assertNotFound();
        $this->get('/db-open-server/oracle/adminer')->assertNotFound();
        auth()->logout();
        $this->get(route('dbtools.server', ['mariadb', 'adminer']))->assertRedirect('/login');
    }

    public function test_a_server_open_to_everyone_needs_attention(): void
    {
        $this->engines['postgresql']['anywhere'] = true;
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->agent->shouldReceive('call')->with('db.server_status')->andReturn(['engines' => $this->engines]);

        $this->get('/')->assertOk()->assertSee('PostgreSQL je přístupná z celého internetu');
    }
}
