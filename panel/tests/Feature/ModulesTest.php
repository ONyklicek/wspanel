<?php

namespace Tests\Feature;

use App\Actions\Server\ManagePython;
use App\Enums\DnsProvider;
use App\Livewire\Pages\ModulesPage;
use App\Models\AgentTask;
use App\Models\Client;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentMessages;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_without_the_clients_module_its_screens_are_closed(): void
    {
        Features::setClients(false);

        $this->get('/clients')->assertForbidden();
        $this->get('/packages')->assertForbidden();
        $this->get('/')->assertOk()->assertDontSee('href="'.url('/clients').'"', false);
    }

    public function test_the_module_is_switched_on_from_the_panel(): void
    {
        Features::setClients(false);

        Livewire::test(ModulesPage::class)->call('mountAction', 'enableClients')->call('callMountedAction');

        $this->assertTrue(Features::clients());
        $this->get('/clients')->assertOk();
    }

    public function test_each_module_says_what_memory_it_takes_and_when_nothing_uses_it(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('mail.hosting')->andReturn(['installed' => true]);
        $agent->shouldReceive('call')->with('sys.versions')->andReturn(['db_engines' => ['mariadb', 'mongodb'], 'dns_local' => true, 'panel_units' => []]);
        $agent->shouldReceive('call')->with('sys.unit_memory', Mockery::on(fn ($p) => in_array('dovecot', $p['patterns'], true) && in_array('postgresql@*', $p['patterns'], true)))->once()->andReturn([
            'dovecot' => ['bytes' => 40 * 1024 * 1024], 'rspamd' => ['bytes' => 100 * 1024 * 1024],
            'pdns' => ['bytes' => 3 * 1024 * 1024], 'mariadb' => ['bytes' => 127 * 1024 * 1024], 'mongod' => ['bytes' => 122 * 1024 * 1024],
        ]);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
        DnsZone::query()->create(['domain' => 'example.cz', 'provider' => DnsProvider::Local]);

        Livewire::test(ModulesPage::class)
            ->assertSee('Jejich služby teď berou 392 MB paměti.')
            ->assertSee('Paměť teď: 140 MB')                      // mail: Dovecot + Rspamd
            ->assertSee('nic ho nepoužívá, odinstalováním uvolníte')  // …and no mail domain
            ->assertSee('Paměť teď: 3 MB')                         // DNS, with a zone: in use
            ->assertSee('MariaDB: 127 MB')
            ->assertSee('MongoDB: 122 MB')
            ->assertSee('nemá žádnou databázi');
    }

    public function test_it_cannot_be_switched_off_while_there_are_clients(): void
    {
        Client::factory()->create();

        Livewire::test(ModulesPage::class)->call('mountAction', 'disableClients')->call('callMountedAction');

        $this->assertTrue(Features::clients());
    }

    public function test_the_installers_choice_is_the_default(): void
    {
        config(['panel.clients' => false]);
        $this->assertFalse(Features::clients());
        config(['panel.clients' => true]);
        $this->assertTrue(Features::clients());
    }

    public function test_mail_hosting_installs_in_the_background(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $agent->shouldReceive('call')->with('mail.hosting')->andReturn(['installed' => false]);
        $agent->shouldReceive('call')->once()->with('mail.install')->andReturn(['job_id' => 'm1']);
        $agent->shouldReceive('call')->with('job.status', ['id' => 'm1'])->andReturn(['status' => 'running'], ['status' => 'failed', 'error' => 'mail-hosting: exit status 1']);
        $this->app->instance(AgentClient::class, $agent);

        $page = $this->pageAction(Livewire::test(ModulesPage::class), 'installMail')
            ->assertNoRedirect()->assertSee('běží na pozadí');

        // Leaving and coming back still shows it; then it reports how it ended.
        Livewire::test(ModulesPage::class)->assertSee('Instalace pošty (schránky)')
            ->call('pollBackgroundJobs')->assertSee('běží na pozadí')
            ->call('pollBackgroundJobs')->assertDontSee('běží na pozadí')->assertNotified();
    }

    /** An agent that reports these modules installed (and answers the rest with nothing). */
    private function agent(array $versions = []): MockInterface
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $agent->shouldReceive('call')->with('sys.versions')->andReturn($versions);
        $this->app->instance(AgentClient::class, $agent);

        return $agent;
    }

    public function test_the_dns_server_is_added_and_taken_away_in_the_background(): void
    {
        $agent = $this->agent(['dns_local' => false]);
        $agent->shouldReceive('call')->once()->with('dns.install')->andReturn(['job_id' => 'd1']);

        $this->pageAction(Livewire::test(ModulesPage::class), 'installDns')->assertSee('běží na pozadí');
        $this->assertDatabaseHas('agent_tasks', ['op' => 'dns.install', 'key' => 'dns', 'job_id' => 'd1']);

        Cache::flush();
        $agent = $this->agent(['dns_local' => true]);
        $agent->shouldReceive('call')->once()->with('dns.uninstall')->andReturn(['job_id' => 'd2']);
        AgentTask::query()->delete();
        $this->pageAction(Livewire::test(ModulesPage::class), 'uninstallDns');
        $this->assertDatabaseHas('agent_tasks', ['op' => 'dns.uninstall', 'job_id' => 'd2']);
    }

    public function test_a_module_still_in_use_is_not_taken_away(): void
    {
        $agent = $this->agent(['dns_local' => true, 'python' => []]);
        $agent->shouldReceive('call')->with('mail.hosting')->andReturn(['installed' => true]);
        $agent->shouldReceive('call')->with('xhprof.status')->andReturn(['installed' => true]);
        foreach (['dns.uninstall', 'mail.uninstall', 'xhprof.uninstall', 'python.remove'] as $op) {
            $agent->shouldNotReceive('call')->with($op);
            $agent->shouldNotReceive('call')->with($op, Mockery::any());
        }
        DnsZone::create(['domain' => 'example.com', 'provider' => DnsProvider::Local]);
        MailDomain::create(['domain' => 'firma.cz']);
        Site::create(['domain' => 'shop.cz', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active', 'xhprof' => ['ratio' => 10]]);
        Site::create(['domain' => 'api.cz', 'mode' => 'python', 'framework' => 'django', 'python_version' => '3.13', 'status' => 'active']);

        $this->pageAction(Livewire::test(ModulesPage::class), 'uninstallDns')->assertNotified();
        $this->pageAction(Livewire::test(ModulesPage::class), 'uninstallMail')->assertNotified();
        $this->pageAction(Livewire::test(ModulesPage::class), 'uninstallXhprof')->assertNotified();
        try {
            app(ManagePython::class)->remove('3.13');
            $this->fail('removed a Python a site runs on');
        } catch (AgentException $e) {
            $this->assertStringContainsString('api.cz', $e->getMessage());
        }
        $this->assertDatabaseCount('agent_tasks', 0);
    }

    public function test_the_agents_refusal_reads_in_czech(): void
    {
        $this->assertSame('Lokální DNS server má ještě zóny (ruční.cz): nejdřív je smažte (Síť → DNS).',
            AgentMessages::cs('the local DNS server still has zones (ruční.cz): delete them first (Síť → DNS)'));
        $this->assertSame('Na Pythonu 3.12 běží a.cz, b.cz: nejdřív je přepněte na jinou verzi, nebo smažte.',
            AgentMessages::cs('Python 3.12 runs a.cz, b.cz: move them to another version or delete them first'));
    }
}
