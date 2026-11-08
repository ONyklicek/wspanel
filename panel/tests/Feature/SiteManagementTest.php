<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Livewire\Sites\CreateSitePage as CreateSite;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class SiteManagementTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn([
            'php' => [['version' => '8.3', 'installed' => true], ['version' => '8.4', 'installed' => true]],
            'php_default' => '8.4',
        ])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    public function test_guests_are_redirected_to_login(): void
    {
        auth()->logout();
        $this->get('/sites')->assertRedirect('/login');
    }

    public function test_creating_a_site_calls_the_agent_and_stores_the_result(): void
    {
        $this->agent->shouldReceive('call')->once()->with('site.create', [
            'domain' => 'shop.example.com',
            'framework' => 'symfony',
            'php_version' => '8.3',
            'mode' => 'classic', 'tls' => 'auto'])->andReturn([
                'mode' => 'classic',
                'framework' => 'symfony',
                'php_version' => '8.3',
                'system_user' => 'web_shop_example_com',
                'home' => '/srv/sites/shop.example.com',
                'doc_root' => '/srv/sites/shop.example.com/current/public',
            ]);

        $this->fillForm(Livewire::test(CreateSite::class), ['domain' => ' HTTPS://Shop.Example.com/ ', 'mode' => 'classic', 'framework' => 'symfony', 'php_version' => '8.3'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect('/sites/1');

        $site = Site::sole();
        $this->assertSame('shop.example.com', $site->domain);
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame('web_shop_example_com', $site->system_user);
    }

    public function test_agent_failure_is_shown_and_no_site_is_left_behind(): void
    {
        $this->agent->shouldReceive('call')->with('site.create', Mockery::any())
            ->andThrow(new AgentException('op_failed', 'php-fpm 8.4 config invalid, not reloading', 'site.create'));

        $this->fillForm(Livewire::test(CreateSite::class), ['domain' => 'broken.example.com', 'framework' => 'laravel', 'php_version' => '8.4'])
            ->call('save')
            ->assertHasErrors(['data.domain']);

        $this->assertSame(0, Site::count());
    }

    public function test_invalid_domains_never_reach_the_agent(): void
    {
        $this->agent->shouldNotReceive('call')->with('site.create', Mockery::any());

        foreach (['localhost', 'a_b.example.com', '-x.com', 'example.com;reboot', '../etc.com'] as $domain) {
            $this->fillForm(Livewire::test(CreateSite::class), ['domain' => $domain, 'framework' => 'laravel', 'php_version' => '8.4'])
                ->call('save')
                ->assertHasErrors(['data.domain']);
        }
        $this->assertSame(0, Site::count());
    }

    public function test_unknown_php_version_is_rejected(): void
    {
        $this->fillForm(Livewire::test(CreateSite::class), ['domain' => 'a.example.com', 'framework' => 'laravel', 'php_version' => '7.4'])
            ->call('save')
            ->assertHasErrors(['data.php_version']);
    }

    public function test_delete_requires_typing_the_domain(): void
    {
        $site = Site::create(['domain' => 'old.example.com', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);

        $this->siteSetting($site, 'delete', ['confirm' => 'wrong.example.com'])
            ->assertHasErrors();
        $this->assertModelExists($site);

        $this->agent->shouldReceive('call')->once()
            ->with('site.delete', ['domain' => 'old.example.com', 'confirm' => 'old.example.com'])
            ->andReturn(['trashed_to' => '/srv/sites/.trash/old.example.com-x']);

        $this->siteSetting($site, 'delete', ['confirm' => 'old.example.com'])
            ->assertHasNoErrors();
        $this->assertModelMissing($site);
    }
}
