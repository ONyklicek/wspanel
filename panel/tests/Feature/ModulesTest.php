<?php

namespace Tests\Feature;

use App\Livewire\Pages\ModulesPage;
use App\Models\Client;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_without_the_clients_module_its_screens_are_closed(): void
    {
        Features::setClients(false);

        $this->get('/clients')->assertForbidden();
        $this->get('/packages')->assertForbidden();
        $this->get('/')->assertOk()->assertDontSee('href="'.url('/clients').'"', false);
    }

    public function test_the_module_is_switched_on_from_the_panel(): void
    {
        Features::setClients(false);

        Livewire::test(ModulesPage::class)->call('mountAction', 'enableClients')->call('callMountedAction');

        $this->assertTrue(Features::clients());
        $this->get('/clients')->assertOk();
    }

    public function test_it_cannot_be_switched_off_while_there_are_clients(): void
    {
        Client::factory()->create();

        Livewire::test(ModulesPage::class)->call('mountAction', 'disableClients')->call('callMountedAction');

        $this->assertTrue(Features::clients());
    }

    public function test_the_installers_choice_is_the_default(): void
    {
        config(['panel.clients' => false]);
        $this->assertFalse(Features::clients());
        config(['panel.clients' => true]);
        $this->assertTrue(Features::clients());
    }
}
