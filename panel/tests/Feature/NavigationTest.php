<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Core\Resources\Workspace;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    /** A parent with children is only their heading, so its own list has to be one of them. */
    public function test_a_parent_lists_its_own_page_among_its_children(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
        $this->actingAs(User::factory()->create());

        $parents = [];
        foreach (app(Workspace::class)->navigation() as $group) {
            foreach ($group->getItems() as $key => $item) {
                if ($item->getChildren() === []) {
                    continue;
                }
                $parents[] = $key;
                $children = array_map(fn (NavigationItem $child) => $child->getUrl(), $item->getChildren());
                $this->assertContains($item->getUrl(), $children, "Navigace: {$key} nemá vlastní stránku mezi potomky.");
            }
        }

        $this->assertEqualsCanonicalizing(['clients', 'databases'], $parents);
    }
}
