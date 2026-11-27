<?php

namespace Tests\Feature;

use App\Actions\Sites\InstallApp;
use App\Models\AgentTask;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class InstallAppTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    /** @var list<string> */
    private array $ops = [];

    private string $env = "DB_DATABASE=\"x\"\n";

    /** @var list<string> */
    private array $engines = ['mariadb'];

    /** @var list<array<string, mixed>> what db.create was asked for */
    private array $dbs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturnUsing(function (string $op, array $p = []) {
            $this->ops[] = $op;

            return match ($op) {
                'sys.versions' => ['db_engines' => $this->engines, 'php' => ['8.4'], 'php_default' => '8.4'],
                'site.create' => ['mode' => $p['mode'] ?? 'classic', 'framework' => $p['framework'], 'php_version' => '8.4', 'system_user' => 'web_x', 'home' => '/srv/sites/x'],
                'db.create' => tap(['password' => null], fn () => $this->dbs[] = $p),
                'env.get' => ['content' => $this->env],
                'env.set' => tap([], fn () => $this->env = $p['content']),
                'deploy.history' => ['deploys' => [['id' => 'r1', 'status' => 'running', 'started' => null, 'finished' => null, 'ref' => null, 'commit' => null, 'error' => null]]],
                default => [],
            };
        });
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    public function test_wordpress_gets_a_site_a_mariadb_database_and_the_unpacked_app(): void
    {
        $out = app(InstallApp::class)->handle(['app' => 'wordpress', 'domain' => 'blog.example.com', 'php_version' => '8.4']);

        $this->assertSame('php', Site::sole()->framework->value);
        $this->assertSame(['site.create', 'db.create', 'site.app_install'], array_values(array_intersect($this->ops, ['site.create', 'db.create', 'site.app_install'])));
        // The app unpacks in the background; the task tells how to finish it.
        $this->assertStringContainsString('na pozadí', $out['note']);
        $this->assertStringContainsString('dokončete instalaci', (string) AgentTask::sole()->body);
    }

    public function test_laravel_is_a_deploy_of_the_official_skeleton_with_a_fresh_key(): void
    {
        app(InstallApp::class)->handle(['app' => 'laravel', 'domain' => 'app.example.com', 'php_version' => '8.4']);

        $this->assertContains('deploy.config', $this->ops);
        $this->assertContains('deploy.run', $this->ops);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $this->env);
        $this->assertStringContainsString('DB_DATABASE="x"', $this->env, 'what the server wrote stays');
    }

    public function test_the_app_gets_the_chosen_engine_and_name_or_none(): void
    {
        $this->engines = ['mariadb', 'postgresql'];
        app(InstallApp::class)->handle(['app' => 'laravel', 'domain' => 'app.example.com', 'php_version' => '8.4', 'db_engine' => 'postgresql', 'db_name' => 'eshop']);
        $this->assertSame('postgresql', $this->dbs[0]['engine']);
        $this->assertSame('eshop', $this->dbs[0]['name']);

        app(InstallApp::class)->handle(['app' => 'symfony', 'domain' => 'sf.example.com', 'php_version' => '8.4', 'db_create' => false]);
        $this->assertCount(1, $this->dbs, 'switched off: no database (the app keeps SQLite)');

        app(InstallApp::class)->handle(['app' => 'wordpress', 'domain' => '9.example.com', 'php_version' => '8.4', 'db_create' => false, 'db_engine' => 'postgresql']);
        $this->assertSame('mariadb', $this->dbs[1]['engine'], 'WordPress always gets one, in MariaDB');
        $this->assertSame('db_9_example_com', $this->dbs[1]['name'], 'a name after the domain starts with a letter');
    }

    public function test_wordpress_needs_mariadb(): void
    {
        $this->engines = ['postgresql'];
        $this->expectException(AgentException::class);
        app(InstallApp::class)->handle(['app' => 'wordpress', 'domain' => 'blog.example.com']);
    }
}
