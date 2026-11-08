<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A table's header buttons must call the table's own header methods: an
 * Action rendered with mountAction() failed on every click in production
 * (MethodNotFoundException, shown as "This page has expired").
 */
class TableHeaderActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_buttons_of_tables_open_the_tables_own_modal(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['/api-tokens' => 'create', '/notification-channels' => 'create'] as $url => $action) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString("openHeaderActionModal(&#039;{$action}&#039;)", $html, $url);
            $this->assertStringNotContainsString("mountAction(&#039;{$action}&#039;)", $html, $url);
        }
    }
}
