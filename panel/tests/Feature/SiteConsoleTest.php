<?php

namespace Tests\Feature;

use App\Livewire\Sites\SiteConsole;
use App\Models\Client;
use App\Models\Package;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SiteConsoleTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->agent->shouldReceive('call')->with('site.quick_commands', Mockery::any())->andReturn(['commands' => [
            ['id' => 'npm-build', 'label' => 'npm run build'], ['id' => 'artisan-migrate', 'label' => 'php artisan migrate --force'],
        ], 'runs' => []])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
    }

    private function site(?int $clientId = null): Site
    {
        $site = Site::create(['domain' => 'app.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active']);
        if ($clientId) {
            $site->forceFill(['client_id' => $clientId])->save();
        }

        return $site;
    }

    public function test_a_quick_command_runs_and_its_output_is_followed(): void
    {
        $this->actingAs(User::factory()->create());
        $site = $this->site();
        $this->agent->shouldReceive('call')->once()->with('site.exec', ['domain' => 'app.example.com', 'command' => 'npm-build'])->andReturn(['run' => '0123456789abcdef']);
        $this->agent->shouldReceive('call')->with('site.exec_output', ['domain' => 'app.example.com', 'run' => '0123456789abcdef', 'offset' => 0])
            ->andReturn(['output' => "\$ npm run build\nbuilt in 2s\n", 'offset' => 30, 'done' => false, 'exit' => null, 'command' => 'npm run build']);
        $this->agent->shouldReceive('call')->with('site.exec_output', ['domain' => 'app.example.com', 'run' => '0123456789abcdef', 'offset' => 30])
            ->andReturn(['output' => "[exit 0]\n", 'offset' => 39, 'done' => true, 'exit' => 0, 'command' => 'npm run build']);

        Livewire::test(SiteConsole::class, ['record' => $site->id])
            ->assertSee('npm run build')->assertSee('Vlastní příkaz')
            ->call('quick', 'rm -rf')
            ->call('quick', 'npm-build')->assertDispatched('console-output', content: "\$ npm run build\nbuilt in 2s\n", reset: true)->assertSet('done', false)
            ->call('follow')->assertDispatched('console-output', content: "[exit 0]\n", reset: false)->assertSet('done', true)->assertSee('hotovo');
    }

    public function test_a_client_without_a_shell_runs_quick_commands_only(): void
    {
        Features::setClients(true);
        $package = Package::create(['name' => 'SFTP', 'ssh' => 'sftp']);
        $client = Client::factory()->create(['package_id' => $package->id]);
        $site = $this->site($client->id);
        $this->actingAs(User::factory()->forClient($client)->create());
        $this->agent->shouldNotReceive('call')->with('site.exec', Mockery::on(fn ($p) => isset($p['custom'])));

        Livewire::test(SiteConsole::class, ['record' => $site->id])
            ->assertDontSee('Vlastní příkaz')
            ->set('line', 'id')->call('custom')->assertNotified();
    }
}
