<?php

namespace Tests\Feature;

use App\Livewire\Clients\ListPackages;
use App\Models\Client;
use App\Models\Package;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class PackageListTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    public function test_the_list_counts_the_clients_and_offers_delete_only_for_an_unused_package(): void
    {
        config(['panel.clients' => true]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
        $this->actingAs(User::factory()->create());
        $used = Package::factory()->create(['name' => 'Firmy']);
        $unused = Package::factory()->create(['name' => 'Prázdný']);
        Client::factory()->count(3)->create(['package_id' => $used->id]);

        $html = Livewire::test(ListPackages::class)->html();

        // The count comes from the table's own query (the column declares it).
        $this->assertMatchesRegularExpression('/Firmy.*?>\s*3\s*</s', $html);
        $this->assertMatchesRegularExpression('/Prázdný.*?>\s*0\s*</s', $html);
        // Delete only where no client uses the package.
        preg_match_all('/openActionModal\(&#039;(\d+)&#039;, &#039;delete&#039;\)/', $html, $deletes);
        $this->assertSame([(string) $unused->id], array_values(array_unique($deletes[1])));
    }
}
