<?php

namespace Tests\Feature;

use App\Actions\Sites\ManageSsh;
use App\Enums\SshAccess;
use App\Livewire\Sites\ListSites;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class SshAndWorkersTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

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
            ->andReturn(['access' => 'sftp', 'keys' => 1, 'password' => false]);

        $this->tableAction(Livewire::test(ListSites::class), $site, 'ssh', ['access' => 'sftp', 'keys' => self::KEY."\n"])
            ->assertHasNoErrors();

        $site->refresh();
        $this->assertSame(SshAccess::Sftp, $site->ssh_access);
        $this->assertSame(self::KEY, $site->ssh_keys);
    }

    public function test_keys_are_required_unless_access_is_off(): void
    {
        $site = $this->site();
        $this->agent->shouldNotReceive('call')->with('ssh.set', Mockery::any());

        $this->tableAction(Livewire::test(ListSites::class), $site, 'ssh', ['access' => 'shell', 'keys' => ''])
            ->assertHasErrors();
    }

    public function test_generated_password_is_sent_to_the_agent_and_not_stored(): void
    {
        $site = $this->site();
        $sent = null;
        $this->agent->shouldReceive('call')->once()
            ->with('ssh.set', Mockery::on(function (array $p) use (&$sent) {
                $sent = $p;

                return $p['access'] === 'sftp' && $p['keys'] === [] && $p['password_mode'] === 'set' && strlen($p['password']) === 20;
            }))
            ->andReturn(['access' => 'sftp', 'keys' => 0, 'password' => true]);

        $this->tableAction(Livewire::test(ListSites::class), $site, 'ssh', ['access' => 'sftp', 'password_mode' => 'generate', 'keys' => ''])
            ->assertHasNoErrors()
            ->assertNotified();

        $site->refresh();
        $this->assertTrue($site->ssh_password);
        $this->assertStringNotContainsString($sent['password'], json_encode($site->getAttributes()));
    }

    public function test_reverb_worker_shows_env_hint(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->once()
            ->with('worker.create', ['domain' => 'app.example.com', 'worker' => 'reverb'])
            ->andReturn(['started' => true, 'port' => 601]);

        $this->tableAction(Livewire::test(ListSites::class), $site, 'addWorker', ['worker' => 'reverb'])
            ->assertHasNoErrors()
            ->assertNotified();
    }
}
