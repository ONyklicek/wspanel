<?php

namespace Tests\Feature;

use App\Actions\Sites\ManageSsh;
use App\Enums\SshAccess;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SshAndWorkersTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGWV8cCv0YxZ7QS0jcjKOzzD8qUuDmzQ4b0V2Rz1fNnS admin@laptop';

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn([
            'php' => [['version' => '8.4', 'installed' => true]],
            'workers' => ['laravel' => ['horizon', 'pulse', 'pulsework', 'queue', 'reverb', 'scheduler']],
            'panel_units' => [],
        ])->byDefault();
        $this->agent->shouldReceive('call')->with('sys.services')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    private function site(): Site
    {
        return Site::create(['domain' => 'app.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active', 'system_user' => 'web_app_example_com']);
    }

    public function test_keys_are_parsed_one_per_line(): void
    {
        $this->assertSame([self::KEY, 'ssh-ed25519 BBBB'], ManageSsh::parseKeys("\n  ".self::KEY."\r\n# komentář\n\nssh-ed25519 BBBB\n"));
    }

    public function test_enabling_sftp_sends_keys_to_the_agent(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->once()
            ->with('ssh.set', ['domain' => 'app.example.com', 'access' => 'sftp', 'keys' => [self::KEY]])
            ->andReturn(['access' => 'sftp', 'keys' => 1]);

        Livewire::test(ListSites::class)
            ->callTableAction('ssh', $site, data: ['access' => 'sftp', 'keys' => self::KEY."\n"])
            ->assertHasNoTableActionErrors();

        $site->refresh();
        $this->assertSame(SshAccess::Sftp, $site->ssh_access);
        $this->assertSame(self::KEY, $site->ssh_keys);
    }

    public function test_keys_are_required_unless_access_is_off(): void
    {
        $site = $this->site();
        $this->agent->shouldNotReceive('call')->with('ssh.set', Mockery::any());

        Livewire::test(ListSites::class)
            ->callTableAction('ssh', $site, data: ['access' => 'shell', 'keys' => ''])
            ->assertHasTableActionErrors(['keys']);
    }

    public function test_reverb_worker_shows_env_hint(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->once()
            ->with('worker.create', ['domain' => 'app.example.com', 'worker' => 'reverb'])
            ->andReturn(['started' => true, 'port' => 601]);

        Livewire::test(ListSites::class)
            ->callTableAction('addWorker', $site, data: ['worker' => 'reverb'])
            ->assertHasNoTableActionErrors()
            ->assertNotified();
    }
}
