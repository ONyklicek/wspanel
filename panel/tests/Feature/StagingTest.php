<?php

namespace Tests\Feature;

use App\Actions\Sites\ManageStaging;
use App\Models\Database;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StagingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $calls = [];

    private int $historyCalls = 0;

    private bool $backupFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturnUsing(function (string $op, array $p = []) {
            $this->calls[] = [$op, $p];

            return match ($op) {
                'site.create' => ['mode' => $p['mode'] ?? 'classic', 'framework' => $p['framework'], 'php_version' => '8.4', 'system_user' => 'web_s', 'home' => '/srv/sites/s'],
                'site.pack' => ['artifact' => 'copy-x.tar.gz'],
                'db.create' => ['password' => null],
                'site.mail' => ['mode' => $p['mode']],
                'site.backup' => ['job_id' => 'j1'],
                'job.status' => $this->backupFails && $p['id'] === 'j1' ? ['status' => 'failed', 'error' => 'disk full', 'op' => 'site.backup'] : ['status' => 'done', 'output' => []],
                'env.get' => ['content' => "APP_URL=https://shop.example.com\n"],
                // The deploy shows as running first, then finished.
                'deploy.history' => ['deploys' => [['id' => 'r1', 'status' => $this->historyCalls++ % 2 === 0 ? 'running' : 'success', 'started' => null, 'finished' => null, 'ref' => null, 'commit' => null, 'error' => null]]],
                'site.edge' => ['edge' => $p],
                default => [],
            };
        });
        $this->app->instance(AgentClient::class, $agent);
        $this->actingAs(User::factory()->create());
    }

    /** @return list<string> */
    private function ops(): array
    {
        return array_column($this->calls, 0);
    }

    /** @return array<string, mixed> */
    private function params(string $op): array
    {
        return collect($this->calls)->firstWhere(0, $op)[1] ?? [];
    }

    private function production(): Site
    {
        $site = Site::create(['domain' => 'shop.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active']);
        Database::create(['engine' => 'mariadb', 'name' => 'shop', 'site_id' => $site->id]);

        return $site;
    }

    public function test_a_staging_copy_has_its_own_database_caught_mail_and_no_indexing(): void
    {
        $staging = app(ManageStaging::class)->create($this->production());

        $this->assertSame('staging.shop.example.com', $staging->domain);
        $this->assertSame(['domain' => 'staging.shop.example.com', 'mode' => 'capture'], $this->params('site.mail'));
        $this->assertSame(['engine' => 'mariadb', 'from' => 'shop', 'to' => 'stg_shop'], $this->params('db.copy'));
        $this->assertSame(['from' => 'shop.example.com', 'to' => 'staging.shop.example.com'], $this->params('site.copy_shared'));
        $this->assertSame(['domain' => 'staging.shop.example.com', 'artifact' => 'copy-x.tar.gz'], $this->params('deploy.run'));
        $this->assertContains(['name' => 'X-Robots-Tag', 'value' => 'noindex, nofollow'], $this->params('site.edge')['headers']);
        $this->assertStringContainsString('APP_URL=https://staging.shop.example.com', $this->params('env.set')['content']);
        $this->assertLessThan(array_search('db.copy', $this->ops()), array_search('site.mail', $this->ops()), 'mail is caught before any data arrives');
    }

    public function test_back_to_production_backs_it_up_first(): void
    {
        $production = $this->production();
        $staging = app(ManageStaging::class)->create($production);
        $this->calls = [];

        app(ManageStaging::class)->push($staging, files: true, databases: true);

        $ops = $this->ops();
        $this->assertSame('site.backup', $ops[0]);
        $this->assertSame(['id' => 'j1'], $this->params('job.status'), 'the push waits for the backup');
        $this->assertLessThan(array_search('db.copy', $ops), array_search('job.status', $ops), 'the backup is done before production is overwritten');
        $this->assertSame(['engine' => 'mariadb', 'from' => 'stg_shop', 'to' => 'shop'], $this->params('db.copy'));
        $this->assertSame(['domain' => 'shop.example.com', 'artifact' => 'copy-x.tar.gz'], $this->params('deploy.run'));
    }

    public function test_a_failed_backup_of_production_stops_the_push(): void
    {
        $staging = app(ManageStaging::class)->create($this->production());
        $this->calls = [];
        $this->backupFails = true;

        try {
            app(ManageStaging::class)->push($staging, files: true, databases: true);
            $this->fail('pushed without a backup of production');
        } catch (AgentException) {
        }

        $this->assertNotContains('db.copy', $this->ops(), 'production was overwritten without its backup');
        $this->assertNotContains('deploy.run', $this->ops());
    }

    public function test_a_staging_copy_is_not_copied_again(): void
    {
        $staging = app(ManageStaging::class)->create($this->production());

        $this->expectExceptionMessage('se nekopíruje');
        app(ManageStaging::class)->create($staging);
    }
}
