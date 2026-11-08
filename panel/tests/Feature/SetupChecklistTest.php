<?php

namespace Tests\Feature;

use App\Livewire\Dashboards\ShowOverview;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Support\SetupChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SetupChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('offsite.get')->andReturn(['configured' => false]);
        $agent->shouldReceive('call')->andReturn([]);
        $this->app->instance(AgentClient::class, $agent);
    }

    public function test_the_overview_lists_what_is_left_until_everything_is_done(): void
    {
        $this->actingAs($admin = User::factory()->create());
        $this->get('/')->assertOk()->assertSee(['Dokončení nastavení serveru', 'Založit první web', 'Zapnout dvoufázové přihlášení', 'Zálohy mimo server']);

        Site::create(['domain' => 'a.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $steps = collect(SetupChecklist::steps())->keyBy('title');
        $this->assertTrue($steps['Založit první web']['done']);
        $this->assertFalse($steps['Nastavit notifikace']['done']);
        $this->assertTrue(SetupChecklist::shown());

        Livewire::test(ShowOverview::class)->call('callWidgetAction', 'setup', 'hideSetup');
        $this->assertFalse(SetupChecklist::shown());
    }

    public function test_it_can_be_hidden_and_is_never_shown_to_a_client(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::put(SetupChecklist::DISMISSED, true);
        $this->get('/')->assertOk()->assertDontSee('Dokončení nastavení serveru');

        Setting::put(SetupChecklist::DISMISSED, false);
        $this->actingAs(User::factory()->forClient(Client::factory()->create())->create());
        $this->assertFalse(SetupChecklist::shown());
    }
}
