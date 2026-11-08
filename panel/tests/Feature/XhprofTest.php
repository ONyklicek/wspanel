<?php

namespace Tests\Feature;

use App\Actions\Sites\ManageXhprof;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class XhprofTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
    }

    private function site(string $domain, ?Client $owner = null, ?array $xhprof = ['ratio' => 50]): Site
    {
        return Site::acrossClients()->create(['domain' => $domain, 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4',
            'status' => 'active', 'client_id' => $owner?->id, 'xhprof' => $xhprof]);
    }

    public function test_switching_profiling_on_and_off(): void
    {
        $this->actingAs(User::factory()->create());
        $site = $this->site('app.example.com', xhprof: null);
        $this->agent->shouldReceive('call')->once()->with('site.xhprof', ['domain' => 'app.example.com', 'enabled' => true, 'ratio' => 10])->andReturn([]);
        $this->agent->shouldReceive('call')->once()->with('site.xhprof', ['domain' => 'app.example.com', 'enabled' => false])->andReturn([]);

        app(ManageXhprof::class)->set($site, 10);
        $this->assertSame(['ratio' => 10], $site->fresh()->xhprof);

        app(ManageXhprof::class)->set($site, null);
        $this->assertNull($site->fresh()->xhprof);
    }

    public function test_the_viewer_lets_in_only_who_may_see_the_site(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $this->site('alice.cz', $alice);
        $this->site('bob.cz', $bob);
        $this->site('off.cz', $alice, null);
        $this->actingAs(User::factory()->forClient($alice)->create());

        $this->get('/auth/xhgui', ['X-Forwarded-Uri' => '/xhgui/alice.cz/run/view?id=1'])->assertNoContent();
        $this->get('/auth/xhgui', ['X-Forwarded-Uri' => '/xhgui/bob.cz/'])->assertForbidden();
        $this->get('/auth/xhgui', ['X-Forwarded-Uri' => '/xhgui/off.cz/'])->assertForbidden();
        $this->get('/auth/xhgui', ['X-Forwarded-Uri' => '/xhgui/../bob.cz/'])->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_and_back(): void
    {
        $this->get('/auth/xhgui', ['X-Forwarded-Uri' => '/xhgui/alice.cz/'])->assertRedirect(route('login'));
        $this->assertStringEndsWith('/xhgui/alice.cz/', (string) session('url.intended'));
    }
}
