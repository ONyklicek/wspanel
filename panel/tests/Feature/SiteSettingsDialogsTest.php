<?php

namespace Tests\Feature;

use App\Livewire\Sites\SiteSettings;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SiteSettingsDialogsTest extends TestCase
{
    use RefreshDatabase;

    /** Every button of the Settings tab opens its dialog (each rendered against the site). */
    public function test_every_settings_dialog_opens(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
        $this->actingAs(User::factory()->create());

        foreach (['classic' => 'laravel', 'worker' => 'symfony', 'node' => 'nuxt'] as $mode => $framework) {
            $site = Site::create(['domain' => $mode.'.example.com', 'mode' => $mode, 'framework' => $framework, 'php_version' => $mode === 'node' ? null : '8.4',
                'node_version' => $mode === 'node' ? '22' : null, 'status' => 'active', 'home' => '/srv/sites/'.$mode.'.example.com']);
            $page = Livewire::test(SiteSettings::class, ['record' => $site->id]);
            preg_match_all('/infolist-action-(open_[A-Za-z]+)/', $page->html(), $buttons);
            $this->assertNotEmpty($buttons[1]);
            foreach (array_unique($buttons[1]) as $button) {
                Livewire::test(SiteSettings::class, ['record' => $site->id])->call('callInfolistAction', $button)->assertOk();
            }
        }
    }
}
