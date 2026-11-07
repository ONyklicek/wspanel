<?php

namespace Tests\Feature;

use App\Actions\Clients\Impersonation;
use App\Actions\Clients\ManageClients;
use App\Actions\Databases\ManageDatabases;
use App\Actions\Databases\SyncDatabases;
use App\Actions\Sites\CreateSite;
use App\Actions\Sites\ManageEdge;
use App\Livewire\Sites\CreateSitePage;
use App\Livewire\Sites\ListSites;
use App\Models\Backup;
use App\Models\Client;
use App\Models\Database;
use App\Models\Package;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Support\Limits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class ClientTenancyTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn([
            'php' => [['version' => '8.3', 'installed' => true], ['version' => '8.4', 'installed' => true]],
            'php_default' => '8.4', 'db_engines' => ['mariadb'],
        ])->byDefault();
        $this->agent->shouldReceive('call')->with('sys.site_disk')->andReturn(['sizes' => []])->byDefault();
        $this->agent->shouldReceive('call')->with('client.create', Mockery::any())->andReturn(['created' => true])->byDefault();
        $this->agent->shouldReceive('call')->with('client.quota', Mockery::any())->andReturn(['enforced' => false])->byDefault();
        $this->agent->shouldReceive('call')->with('site.limits', Mockery::any())->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
    }

    private function site(string $domain, ?Client $client = null, bool $enabled = true): Site
    {
        $user = 'web_'.str_replace('.', '_', $domain);

        return Site::acrossClients()->create([
            'domain' => $domain, 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active',
            'enabled' => $enabled, 'system_user' => $user, 'home' => "/srv/sites/{$domain}", 'doc_root' => "/srv/sites/{$domain}/current/public",
            'client_id' => $client?->id,
        ]);
    }

    private function signInAs(Client $client): User
    {
        $user = User::factory()->forClient($client)->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_a_client_lists_only_its_own_sites(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $this->site('alice.example.com', $alice);
        $this->site('bob.example.com', $bob);
        $this->site('admin.example.com');
        $this->signInAs($alice);

        Livewire::test(ListSites::class)
            ->assertSee('alice.example.com')
            ->assertDontSee('bob.example.com')
            ->assertDontSee('admin.example.com');
    }

    public function test_an_admin_lists_every_site(): void
    {
        $alice = Client::factory()->create();
        $this->site('alice.example.com', $alice);
        $this->site('admin.example.com');
        $this->actingAs(User::factory()->create());

        Livewire::test(ListSites::class)->assertSee('alice.example.com')->assertSee('admin.example.com');
    }

    public function test_another_clients_site_is_not_found(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $foreign = $this->site('bob.example.com', $bob);
        $this->signInAs($alice);

        $this->get("/sites/{$foreign->id}")->assertNotFound();
        $this->get("/sites/{$foreign->id}/files/download?path=x")->assertNotFound();
    }

    public function test_another_clients_backup_cannot_be_downloaded(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $db = Database::acrossClients()->create(['engine' => 'mariadb', 'name' => 'c2_shop', 'client_id' => $bob->id]);
        $backup = Backup::withoutGlobalScopes()->create(['database_id' => $db->id, 'file' => 'c2_shop-2026.sql.gz', 'size' => 10, 'taken_at' => now()]);
        $this->signInAs($alice);

        $this->get("/backups/{$backup->id}/download")->assertNotFound();
        $this->get("/db-open/{$db->id}/adminer")->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function adminOnlyScreens(): array
    {
        return [
            'clients' => ['/clients'], 'packages' => ['/packages'], 'firewall' => ['/firewall'], 'services' => ['/services'],
            'php' => ['/php'], 'audit' => ['/audit'], 'mail server' => ['/mail'], 'users' => ['/users'],
            'server db tool' => ['/db-open-server/mariadb/adminer'],
        ];
    }

    #[DataProvider('adminOnlyScreens')]
    public function test_a_client_cannot_open_server_screens(string $url): void
    {
        $this->signInAs(Client::factory()->create());

        $this->get($url)->assertForbidden();
    }

    public function test_a_client_site_belongs_to_the_client(): void
    {
        $alice = Client::factory()->create();
        $this->signInAs($alice);
        $this->agent->shouldReceive('call')->once()->with('site.create', Mockery::any())->andReturn([
            'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'system_user' => 'web_new_example_com',
            'home' => '/srv/sites/new.example.com', 'doc_root' => '/srv/sites/new.example.com/current/public',
        ]);

        $this->fillForm(Livewire::test(CreateSitePage::class), ['domain' => 'new.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($alice->id, Site::acrossClients()->where('domain', 'new.example.com')->value('client_id'));
    }

    public function test_a_client_cannot_create_a_site_past_its_package(): void
    {
        $alice = Client::factory()->for(Package::factory()->state(['max_sites' => 1]))->create();
        $this->site('first.example.com', $alice);
        $this->signInAs($alice);
        $this->agent->shouldNotReceive('call')->with('site.create', Mockery::any());

        $component = $this->fillForm(Livewire::test(CreateSitePage::class), ['domain' => 'second.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4'])
            ->call('save')
            ->assertHasErrors('data.domain');

        $this->assertSame('Limit balíčku: Weby 1 z 1. Pro víc kontaktujte správce.', $component->errors()->first('data.domain'));
    }

    public function test_a_domain_of_another_client_is_reported_as_taken(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $this->site('taken.example.com', $bob);
        $this->signInAs($alice);

        $this->fillForm(Livewire::test(CreateSitePage::class), ['domain' => 'taken.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4'])
            ->call('save')
            ->assertHasErrors(['data.domain' => 'Web s touto doménou už existuje.']);
    }

    public function test_a_mode_the_package_does_not_allow_is_refused(): void
    {
        $alice = Client::factory()->for(Package::factory()->state(['allowed_modes' => ['classic']]))->create();
        $this->signInAs($alice);

        $this->expectExceptionMessage('Tento režim webu balíček nepovoluje.');
        app(CreateSite::class)->handle(['domain' => 'w.example.com', 'mode' => 'worker', 'framework' => 'laravel']);
    }

    public function test_an_admin_may_go_past_a_clients_limit(): void
    {
        $alice = Client::factory()->for(Package::factory()->state(['max_sites' => 0]))->create();
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->once()->with('site.create', Mockery::any())->andReturn([
            'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'system_user' => 'web_x_example_com',
            'home' => '/srv/sites/x.example.com', 'doc_root' => '/srv/sites/x.example.com/current/public',
        ]);

        $site = app(CreateSite::class)->handle(['domain' => 'x.example.com', 'framework' => 'laravel', 'php_version' => '8.4', 'client_id' => $alice->id]);

        $this->assertSame($alice->id, $site->client_id);
    }

    public function test_a_clients_database_is_prefixed_and_counted(): void
    {
        $alice = Client::factory()->for(Package::factory()->state(['max_databases' => 1]))->create();
        $this->signInAs($alice);
        $this->agent->shouldReceive('call')->once()->with('db.create', ['engine' => 'mariadb', 'name' => "c{$alice->id}_shop"])->andReturn(['password' => 'p']);

        $db = app(ManageDatabases::class)->create('mariadb', 'shop', null)['database'];

        $this->assertSame("c{$alice->id}_shop", $db->name);
        $this->assertSame($alice->id, $db->client_id);
        $this->expectExceptionMessage('Limit balíčku: Databáze 1 z 1.');
        app(ManageDatabases::class)->create('mariadb', 'blog', null);
    }

    public function test_an_override_beats_the_package_and_null_means_unlimited(): void
    {
        $client = Client::factory()->for(Package::factory()->state(['max_sites' => 2, 'max_databases' => 2]))
            ->create(['overrides' => ['max_sites' => 5, 'max_databases' => null]]);

        $usage = app(Limits::class)->usage($client);

        $this->assertSame(5, $usage['sites']['limit']);
        $this->assertNull($usage['databases']['limit']);
        $this->assertSame(5, $usage['cron']['limit']);
    }

    public function test_a_suspended_clients_user_is_signed_out(): void
    {
        $this->signInAs(Client::factory()->suspended()->create());

        $this->get('/sites')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_suspend_stops_running_sites_and_resume_starts_only_those(): void
    {
        $alice = Client::factory()->create();
        $running = $this->site('run.example.com', $alice);
        $this->site('off.example.com', $alice, enabled: false);
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->with('sys.services')->andReturn([]);
        $this->agent->shouldReceive('call')->with('site.health', Mockery::any())->andReturn(['ok' => true, 'status' => 200]);
        $this->agent->shouldReceive('call')->once()->with('site.disable', ['domain' => 'run.example.com'])->andReturn([]);
        $this->agent->shouldReceive('call')->once()->with('site.enable', ['domain' => 'run.example.com'])->andReturn([]);

        app(ManageClients::class)->suspend($alice);
        $this->assertTrue($alice->fresh()->isSuspended());
        $this->assertFalse($running->fresh()->enabled);

        app(ManageClients::class)->resume($alice);
        $this->assertFalse($alice->fresh()->isSuspended());
        $this->assertTrue($running->fresh()->enabled);
    }

    public function test_a_client_that_owns_sites_cannot_be_deleted(): void
    {
        $alice = Client::factory()->create();
        $this->site('keep.example.com', $alice);

        $this->expectExceptionMessage('Klient ještě vlastní weby');
        app(ManageClients::class)->delete($alice);
    }

    public function test_an_admin_can_look_as_a_client_and_come_back(): void
    {
        $alice = Client::factory()->create();
        $clientUser = User::factory()->forClient($alice)->create();
        $admin = User::factory()->create();
        $this->actingAs($admin);

        app(Impersonation::class)->start($admin, $clientUser);
        $this->assertAuthenticatedAs($clientUser);
        $this->get('/firewall')->assertForbidden();

        $this->post('/impersonation/leave')->assertRedirect('/clients');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_sync_triggered_by_a_client_neither_hides_nor_takes_other_rows(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        Database::acrossClients()->create(['engine' => 'mariadb', 'name' => 'c2_shop', 'client_id' => $bob->id]);
        $this->signInAs($alice);
        $this->agent->shouldReceive('call')->with('db.list', ['engine' => 'mariadb'])->andReturn([
            ['name' => 'c2_shop', 'size' => 100], ['name' => 'found_on_server', 'size' => 5],
        ]);
        $this->agent->shouldReceive('call')->with('db.backups', Mockery::any())->andReturn(['backups' => [], 'schedule' => ['frequency' => 'off', 'keep' => 7]]);

        app(SyncDatabases::class)->handle();

        $this->assertSame(2, Database::acrossClients()->count());
        $this->assertSame($bob->id, Database::acrossClients()->where('name', 'c2_shop')->value('client_id'));
        $this->assertNull(Database::acrossClients()->where('name', 'found_on_server')->value('client_id'));
    }

    public function test_client_and_package_screens_render_for_an_admin(): void
    {
        $alice = Client::factory()->create(['name' => 'Alice s.r.o.']);
        $this->site('alice.example.com', $alice);
        $this->actingAs(User::factory()->create());

        $this->get('/clients')->assertOk()->assertSee('Alice s.r.o.');
        $this->get("/clients/{$alice->id}")->assertOk()->assertSee('Spotřeba a limity')->assertSee('alice.example.com');
        $this->get('/packages')->assertOk()->assertSee($alice->package->name);
    }

    public function test_a_client_overview_shows_its_account_not_the_server(): void
    {
        $alice = Client::factory()->create();
        $this->site('alice.example.com', $alice);
        $this->signInAs($alice);
        $this->agent->shouldNotReceive('call')->with('sys.metrics');

        $this->get('/')->assertOk()->assertSee('Můj účet')->assertSee('alice.example.com')->assertDontSee('Služby');
    }

    public function test_mailpit_is_for_administrators_only(): void
    {
        $this->signInAs(Client::factory()->create());

        $this->get('/auth/check')->assertForbidden();
    }

    public function test_an_alias_of_another_clients_site_counts_as_taken(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $this->site('bob.example.com', $bob)->update(['edge_settings' => ['aliases' => ['www.bob.example.com']]]);
        $this->signInAs($alice);

        $this->fillForm(Livewire::test(CreateSitePage::class), ['domain' => 'www.bob.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4'])
            ->call('save')
            ->assertHasErrors('data.domain');
    }

    public function test_extra_domains_count_against_the_package(): void
    {
        $alice = Client::factory()->for(Package::factory()->state(['max_aliases' => 1]))->create();
        $site = $this->site('alice.example.com', $alice);
        $this->signInAs($alice);
        $this->agent->shouldNotReceive('call')->with('site.edge', Mockery::any());

        $this->expectExceptionMessage('Limit balíčku: Další domény webů 0 z 1.');
        app(ManageEdge::class)->set($site, ['aliases' => ['a.example.com', 'b.example.com']]);
    }

    public function test_a_new_client_gets_its_group_and_disk_quota_on_the_server(): void
    {
        $package = Package::factory()->create(['disk_quota_mb' => 2048]);
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->once()->with('client.create', ['group' => 'wsc_1'])->andReturn(['created' => true]);
        $this->agent->shouldReceive('call')->once()->with('client.quota', ['group' => 'wsc_1', 'mb' => 2048])->andReturn(['enforced' => true]);

        $client = app(ManageClients::class)->create(['name' => 'Alice', 'package_id' => $package->id, 'login_email' => 'alice@example.com', 'login_password' => 'Heslo-12345678']);

        $this->assertSame('wsc_1', $client->linux_group);
        $this->assertTrue($client->disk_enforced);
        $this->assertSame($client->id, User::where('email', 'alice@example.com')->value('client_id'));
    }

    public function test_a_client_site_is_created_in_the_client_group_with_its_process_limit(): void
    {
        $alice = Client::factory()->for(Package::factory()->state(['fpm_children' => 4]))->create(['linux_group' => 'wsc_1']);
        $this->signInAs($alice);
        $this->agent->shouldReceive('call')->once()->with('site.create', Mockery::on(fn ($p) => $p['group'] === 'wsc_1' && $p['fpm_children'] === 4))
            ->andReturn(['mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'system_user' => 'web_g_example_com', 'home' => '/srv/sites/g.example.com', 'doc_root' => '/srv/sites/g.example.com/current/public']);

        app(\App\Actions\Sites\CreateSite::class)->handle(['domain' => 'g.example.com', 'framework' => 'laravel', 'php_version' => '8.4']);

        $this->assertSame($alice->id, Site::acrossClients()->where('domain', 'g.example.com')->value('client_id'));
    }

    public function test_assigning_a_site_moves_its_files_to_the_client_group(): void
    {
        $alice = Client::factory()->create(['linux_group' => 'wsc_1']);
        $site = $this->site('move.example.com');
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->once()->with('site.set_group', ['domain' => 'move.example.com', 'group' => 'wsc_1'])->andReturn(['job_id' => 'j1']);
        $this->agent->shouldReceive('call')->with('job.status', ['id' => 'j1'])->andReturn(['status' => 'done', 'output' => ['changed' => true]]);

        app(ManageClients::class)->assignSite($site, $alice);

        $this->assertSame($alice->id, $site->fresh()->client_id);
    }
}
