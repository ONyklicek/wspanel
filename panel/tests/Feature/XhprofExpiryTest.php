<?php

namespace Tests\Feature;

use App\Actions\Sites\ManageXhprof;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class XhprofExpiryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Profiling slows every measured request several times over: forgotten at
     * 100 %, it made a production site's Livewire pages 3-7x slower for days.
     */
    public function test_profiling_runs_for_a_while_and_then_switches_itself_off(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('site.xhprof', ['domain' => 'shop.example.com', 'enabled' => true, 'ratio' => 10])->once()->andReturn([]);
        $agent->shouldReceive('call')->with('site.xhprof', ['domain' => 'shop.example.com', 'enabled' => false])->once()->andReturn([]);
        $this->app->instance(AgentClient::class, $agent);
        $site = Site::create(['domain' => 'shop.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active']);
        $legacy = Site::create(['domain' => 'old.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active', 'xhprof' => ['ratio' => 100]]);
        $xhprof = app(ManageXhprof::class);

        $xhprof->set($site, 10, 2);
        $this->assertSame(0, $xhprof->expire(), 'switched off before its time');
        $this->assertNotNull($legacy->fresh()->xhprof['until'] ?? null, 'profiling from before an end existed gets one');

        $this->travel(3)->hours();
        $this->assertSame(1, $xhprof->expire());
        $this->assertNull($site->fresh()->xhprof);
        $this->assertNotNull($legacy->fresh()->xhprof, 'its day is not over yet');
    }
}
