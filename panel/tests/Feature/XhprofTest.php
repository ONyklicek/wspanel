<?php

namespace Tests\Feature;

use App\Actions\Sites\ManageXhprof;
use App\Http\Controllers\XhguiAuthController;
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

    public function test_the_panel_link_signs_in_to_the_viewer_on_the_sites_own_domain(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $alices = $this->site('alice.cz', $alice);
        $bobs = $this->site('bob.cz', $bob);
        $this->actingAs(User::factory()->forClient($alice)->create());

        $this->get('/sites/'.$bobs->id.'/xhgui')->assertNotFound();
        $link = $this->get('/sites/'.$alices->id.'/xhgui')->assertRedirect()->headers->get('Location');
        $this->assertMatchesRegularExpression('#^https://alice\.cz(:\d+)?/_wsp_xhgui/\?wsp_token=[a-f0-9]{48}$#', $link);
        $token = substr($link, strpos($link, 'wsp_token=') + 10);
        auth()->logout();

        // The edge asks about the domain of its own block; a token for another
        // domain is refused there (and spent).
        $other = XhguiAuthController::link($alices);
        $this->get('/auth/xhgui/bob.cz', ['X-Forwarded-Uri' => '/_wsp_xhgui/?wsp_token='.substr($other, strpos($other, 'wsp_token=') + 10)])->assertForbidden();
        $signIn = $this->get('/auth/xhgui/alice.cz', ['X-Forwarded-Uri' => '/_wsp_xhgui/?wsp_token='.$token])
            ->assertRedirect('/_wsp_xhgui/');
        $setCookie = (string) $signIn->headers->get('Set-Cookie');
        $this->assertStringContainsStringIgnoringCase('path=/_wsp_xhgui/', $setCookie);
        $this->assertStringNotContainsString('session', strtolower(implode(' ', $signIn->headers->all('set-cookie'))), 'no panel session on a site\'s domain');
        $cookie = explode(';', $setCookie)[0];

        $this->get('/auth/xhgui/alice.cz', ['X-Forwarded-Uri' => '/_wsp_xhgui/?wsp_token='.$token])->assertForbidden();
        $this->get('/auth/xhgui/alice.cz', ['X-Forwarded-Uri' => '/_wsp_xhgui/run/view', 'Cookie' => $cookie])->assertNoContent();
        $this->get('/auth/xhgui/bob.cz', ['X-Forwarded-Uri' => '/_wsp_xhgui/', 'Cookie' => $cookie])->assertForbidden();
        $this->get('/auth/xhgui/alice.cz', ['X-Forwarded-Uri' => '/_wsp_xhgui/'])->assertForbidden();
    }
}
