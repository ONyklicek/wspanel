<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactor;
use App\Livewire\Pages\Fail2banPage;
use App\Livewire\Pages\UpdatesPage;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SecurityUpdatesTest extends TestCase
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

    public function test_required_two_factor_sends_a_client_to_set_it_up_first(): void
    {
        Setting::put(EnsureTwoFactor::SETTING, 'clients');
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->forClient($client)->create());

        $this->get('/sites')->assertRedirect(route('wire.users.profile'));
        $this->get(route('wire.users.profile'))->assertOk();
    }

    public function test_it_lets_in_who_has_it_and_whom_it_does_not_concern(): void
    {
        Setting::put(EnsureTwoFactor::SETTING, 'clients');
        $this->actingAs(User::factory()->create());
        $this->get('/sites')->assertOk(); // the administrator, policy "clients"

        $client = Client::factory()->create();
        $user = User::factory()->forClient($client)->create();
        $user->forceFill(['two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($user)->get('/sites')->assertOk();

        Setting::put(EnsureTwoFactor::SETTING, 'all');
        $this->actingAs(User::factory()->create())->get('/sites')->assertRedirect(route('wire.users.profile'));
    }

    public function test_the_updates_page_lists_packages_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->with('sys.updates', [])->andReturn([
            'packages' => [['name' => 'libssl3t64', 'from' => '3.5.1-1', 'to' => '3.5.1-1+deb13u1', 'security' => true]],
            'reboot_required' => true, 'auto_security' => false,
        ]);
        $this->agent->shouldNotReceive('call')->with('fail2ban.status');

        Livewire::test(UpdatesPage::class)
            ->assertSee('libssl3t64')->assertSee('bezpečnostní')->assertSee('Server potřebuje restart')->assertDontSee('fail2ban');
    }

    public function test_the_fail2ban_page_lists_and_unbans_an_address(): void
    {
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->with('fail2ban.status')->andReturn(['jails' => ['sshd' => ['203.0.113.5']]]);
        $this->agent->shouldReceive('call')->once()->with('fail2ban.unban', ['ip' => '203.0.113.5'])->andReturn([]);

        Livewire::test(Fail2banPage::class)
            ->assertSee('203.0.113.5')->assertSee('sshd')
            ->call('unban', '203.0.113.5')
            ->assertNotified();
    }

    public function test_clients_cannot_open_the_updates_page(): void
    {
        $this->actingAs(User::factory()->forClient(Client::factory()->create())->create());

        $this->get('/updates')->assertForbidden();
        $this->get('/fail2ban')->assertForbidden();
    }
}
