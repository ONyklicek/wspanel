<?php

namespace Tests\Feature;

use App\Livewire\Pages\ServerLogsPage;
use App\Models\Client;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ServerLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_administrator_reads_a_source_with_a_filter(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('sys.log', ['source' => 'mail', 'since' => '1h', 'priority' => 'all', 'filter' => '', 'lines' => 1000])
            ->andReturn(['lines' => ['2026-11-08T10:00:00 postfix/smtp[1]: 8843E1C077: to=<a@b.cz>, status=sent']]);
        $agent->shouldReceive('call')->with('sys.log', ['source' => 'ssh', 'since' => '24h', 'priority' => 'all', 'filter' => '203.0.113.9', 'lines' => 1000])
            ->once()->andReturn(['lines' => []]);
        $agent->shouldReceive('call')->andReturn([]);
        $this->app->instance(AgentClient::class, $agent);
        $this->actingAs(User::factory()->create());

        Livewire::test(ServerLogsPage::class)->assertSee('status=sent')
            ->set('since', '24h')->set('filter', '203.0.113.9')->set('source', 'ssh')
            ->assertSee('V tomto období nic.');

        $this->get('/server-logs')->assertOk();
        $this->actingAs(User::factory()->forClient(Client::factory()->create())->create());
        $this->get('/server-logs')->assertForbidden();
    }
}
