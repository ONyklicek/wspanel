<?php

namespace Tests\Feature;

use App\Actions\Sites\ImportSite;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ImportSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_dump_gets_its_own_database_of_the_site(): void
    {
        $this->actingAs(User::factory()->create());
        $site = Site::create(['domain' => 'old.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $sent = null;
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturnUsing(function (string $op, array $p = []) use (&$sent) {
            return match ($op) {
                'site.import_scan' => ['cpanel' => true, 'dumps' => ['jan_WP']],
                'db.create' => ['password' => null],
                'site.import' => tap(['databases' => ['jan_wp'], 'wp_config' => 'updated'], function () use ($p, &$sent) {
                    $sent = $p;
                }),
                default => [],
            };
        });
        $this->app->instance(AgentClient::class, $agent);

        $out = app(ImportSite::class)->import($site, 'cpmove-jan.tar.gz');

        $this->assertSame(['jan_WP' => 'jan_wp'], (array) $sent['databases']);
        $this->assertSame('jan_wp', $site->databases()->sole()->name);
        $this->assertSame('updated', $out['wp_config']);
    }
}
