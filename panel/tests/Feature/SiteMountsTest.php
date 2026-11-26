<?php

namespace Tests\Feature;

use App\Livewire\Sites\SiteSettings;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class SiteMountsTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
    }

    public function test_an_smb_share_is_connected_with_its_password_handed_only_to_the_agent(): void
    {
        $this->actingAs(User::factory()->create());
        $site = Site::create(['domain' => 'nas.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active', 'home' => '/srv/sites/nas.example.com']);
        $this->agent->shouldReceive('call')->once()->with('site.mount', [
            'domain' => 'nas.example.com', 'action' => 'add',
            'mount' => ['name' => 'nas', 'type' => 'smb', 'host' => 'nas.firma.lan', 'share' => 'Data', 'path' => 'web', 'user' => 'web', 'version' => '3.0', 'tls' => 'explicit', 'port' => 0, 'read_only' => false, 'encrypt' => true],
            'password' => 'Tajne-heslo-1',
        ])->andReturn(['name' => 'nas']);

        $this->siteSetting($site, 'mountAdd', ['type' => 'smb', 'host' => 'nas.firma.lan', 'share' => 'Data', 'path' => 'web', 'user' => 'web', 'password' => 'Tajne-heslo-1', 'name' => 'nas'])
            ->assertHasNoErrors()->assertNotified('Disk nas připojen');
    }

    public function test_encryption_needs_smb_3(): void
    {
        $this->actingAs(User::factory()->create());
        $site = Site::create(['domain' => 'nas.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $this->agent->shouldNotReceive('call')->with('site.mount', Mockery::any());

        $this->siteSetting($site, 'mountAdd', ['type' => 'smb', 'host' => 'nas.firma.lan', 'share' => 'Data', 'user' => 'web', 'password' => 'x', 'name' => 'nas', 'version' => '2.1', 'encrypt' => true])
            ->assertHasErrors();
    }

    public function test_the_settings_show_the_disks_and_whether_they_are_connected(): void
    {
        $this->actingAs(User::factory()->create());
        $site = Site::create(['domain' => 'nas.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $this->agent->shouldReceive('call')->with('site.mounts', ['domain' => 'nas.example.com'])->andReturn(['mounts' => [
            ['mount' => ['name' => 'box', 'type' => 'ftp', 'host' => 'ftp.example.net', 'path' => 'in', 'user' => 'u'], 'path' => '/srv/sites/nas.example.com/mounts/box', 'unit' => 'x', 'active' => 'failed'],
        ]]);

        Livewire::test(SiteSettings::class, ['record' => $site->id])
            ->assertSee('Síťové disky')
            ->assertSee('box · NEPŘIPOJENO · ftp://ftp.example.net/in → /srv/sites/nas.example.com/mounts/box');
    }

    public function test_a_client_cannot_reach_into_the_network_with_a_disk(): void
    {
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->forClient($client)->create());
        $site = Site::create(['domain' => 'c.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active', 'client_id' => $client->id]);
        $this->agent->shouldNotReceive('call')->with('site.mount', Mockery::any());

        Livewire::test(SiteSettings::class, ['record' => $site->id])->assertDontSee('Síťové disky');
    }

    public function test_the_share_and_the_folder_are_picked_from_what_the_server_offers(): void
    {
        $this->actingAs(User::factory()->create());
        $site = Site::create(['domain' => 'nas.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active', 'home' => '/srv/sites/nas.example.com']);
        $login = ['host' => 'nas.firma.lan', 'user' => 'web', 'password' => 'Tajne-heslo-1', 'encrypt' => true];
        $this->agent->shouldReceive('call')->with('smb.browse', $login)->andReturn(['shares' => [['name' => 'Data', 'comment' => 'Sdílená data'], ['name' => 'backup']]]);
        $this->agent->shouldReceive('call')->with('smb.browse', $login + ['share' => 'Data'])->andReturn(['share' => 'Data', 'path' => '', 'dirs' => ['web', 'foto'], 'writable' => false]);
        $this->agent->shouldReceive('call')->with('smb.browse', $login + ['share' => 'Data', 'path' => 'web'])->andReturn(['share' => 'Data', 'path' => 'web', 'dirs' => ['uploads'], 'writable' => true]);
        $this->agent->shouldReceive('call')->once()->with('site.mount', Mockery::on(fn ($p) => $p['mount']['share'] === 'Data' && $p['mount']['path'] === 'web'
            && ! array_key_exists('smb_shares', $p['mount'])))->andReturn(['name' => 'nas']);

        Livewire::test(SiteSettings::class, ['record' => $site->id])
            ->call('mountAction', 'mountAdd')
            ->set('mountedActions.0.data.host', 'nas.firma.lan')
            ->set('mountedActions.0.data.user', 'web')
            ->set('mountedActions.0.data.password', 'Tajne-heslo-1')
            ->call('callFieldAction', 'mountedActions.0.data.smb_browse', 'smb_browse')
            ->assertNotified('Nalezeno sdílených složek: 2')
            ->assertSee('Data – Sdílená data')
            ->set('mountedActions.0.data.smb_share_pick', 'Data')
            ->assertSet('mountedActions.0.data.share', 'Data')
            ->assertSee('Do této složky účet zapisovat nesmí')
            ->set('mountedActions.0.data.smb_dir_pick', 'web')
            ->assertSet('mountedActions.0.data.path', 'web')
            ->assertSee('Do této složky účet smí zapisovat')
            ->call('callMountedAction')
            ->assertHasNoErrors();
    }

    public function test_a_disk_the_account_may_not_write_to_says_so(): void
    {
        $this->actingAs(User::factory()->create());
        $site = Site::create(['domain' => 'nas.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active', 'home' => '/srv/sites/nas.example.com']);
        $this->agent->shouldReceive('call')->with('site.mount', Mockery::any())->andReturn(['name' => 'nas', 'writable' => false]);

        $this->siteSetting($site, 'mountAdd', ['type' => 'smb', 'host' => 'nas.firma.lan', 'share' => 'Data', 'user' => 'web', 'password' => 'x', 'name' => 'nas'])
            ->assertHasNoErrors()->assertNotified('účet na něj nesmí zapisovat');
    }
}
