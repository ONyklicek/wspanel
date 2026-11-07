<?php

namespace Tests\Feature;

use App\Filament\Pages\AuditLog;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_agent_audit_entries_with_area_filter(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('audit.tail', ['limit' => 500])->andReturn([
            ['ts' => '2026-11-07T10:00:00Z', 'op' => 'site.create', 'params' => ['domain' => 'a.example.com'], 'peer_uid' => 995, 'ok' => true, 'duration_ms' => 120],
            ['ts' => '2026-11-07T09:00:00Z', 'op' => 'env.set', 'params' => ['domain' => 'a.example.com', 'content' => '<redacted 20 bytes sha256:abc>'], 'peer_uid' => 995, 'ok' => false, 'error' => 'boom', 'duration_ms' => 3],
        ]);
        $agent->shouldReceive('call')->with('audit.tail', ['limit' => 500, 'prefix' => 'db.'])->andReturn([]);
        $this->app->instance(AgentClient::class, $agent);
        $this->actingAs(User::factory()->create());

        Livewire::test(AuditLog::class)
            ->assertSee('site.create')
            ->assertSee('redacted 20 bytes')
            ->filterTable('area', 'db.')
            ->assertDontSee('site.create');
    }

    public function test_guests_cannot_see_the_audit_log(): void
    {
        $this->get('/audit-log')->assertRedirect('/login');
    }
}
