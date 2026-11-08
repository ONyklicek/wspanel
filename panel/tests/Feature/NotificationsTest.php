<?php

namespace Tests\Feature;

use App\Livewire\NotificationChannelTable;
use App\Models\Client;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Notify\Alerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{key: string, client_id: int|null, level: string, title: string, detail: string}> */
    private array $problems = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $this->app->instance(Alerts::class, new class($this) extends Alerts
        {
            public function __construct(private NotificationsTest $test) {}

            public function current(): array
            {
                return $this->test->problems();
            }
        });
    }

    public function problems(): array
    {
        return $this->problems;
    }

    private function slack(User $user): NotificationChannel
    {
        return NotificationChannel::withoutGlobalScopes()->create(['user_id' => $user->id, 'type' => 'slack', 'name' => 'tým',
            'config' => ['url' => 'https://hooks.slack.com/services/T000/B000/'.$user->id.'xxxxxxxxxx'], 'events' => ['problems', 'resolved']]);
    }

    /** @return list<string> texts posted to the user's Slack hook */
    private function postedFor(User $user): array
    {
        return Http::recorded()->filter(fn ($r) => str_ends_with($r[0]->url(), '/'.$user->id.'xxxxxxxxxx'))->map(fn ($r) => $r[0]['text'])->values()->all();
    }

    public function test_a_problem_is_announced_once_confirmed_and_again_when_resolved(): void
    {
        $admin = User::factory()->create();
        $this->slack($admin);
        $this->problems = [['key' => 'site-down:a.cz', 'client_id' => null, 'level' => 'danger', 'title' => 'a.cz neodpovídá', 'detail' => 'HTTP 502']];

        $this->artisan('panel:alerts')->assertSuccessful();
        $this->assertSame([], $this->postedFor($admin), 'one failed check is not worth a message');

        $this->artisan('panel:alerts')->assertSuccessful();
        $this->artisan('panel:alerts')->assertSuccessful();
        $this->assertSame(["Problém: a.cz neodpovídá\nHTTP 502"], $this->postedFor($admin), 'announced once');

        $this->problems = [];
        $this->artisan('panel:alerts')->assertSuccessful();
        $this->assertSame(["Problém: a.cz neodpovídá\nHTTP 502", 'Vyřešeno: a.cz neodpovídá'], $this->postedFor($admin));
    }

    public function test_a_blip_that_never_got_announced_is_not_resolved_either(): void
    {
        $admin = User::factory()->create();
        $this->slack($admin);
        $this->problems = [['key' => 'x', 'client_id' => null, 'level' => 'danger', 'title' => 'x', 'detail' => '']];
        $this->artisan('panel:alerts');
        $this->problems = [];
        $this->artisan('panel:alerts');

        $this->assertSame([], $this->postedFor($admin));
    }

    public function test_a_client_hears_only_about_its_own_sites(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $aliceUser = User::factory()->forClient($alice)->create();
        $admin = User::factory()->create();
        $this->slack($aliceUser);
        $this->slack($admin);
        $this->problems = [
            ['key' => 'site-down:alice.cz', 'client_id' => $alice->id, 'level' => 'danger', 'title' => 'alice.cz neodpovídá', 'detail' => ''],
            ['key' => 'site-down:bob.cz', 'client_id' => $bob->id, 'level' => 'danger', 'title' => 'bob.cz neodpovídá', 'detail' => ''],
            ['key' => 'disk:/', 'client_id' => null, 'level' => 'danger', 'title' => 'Disk / je zaplněný', 'detail' => ''],
        ];
        $this->artisan('panel:alerts');
        $this->artisan('panel:alerts');

        $this->assertSame(['Problém: alice.cz neodpovídá'], $this->postedFor($aliceUser));
        $this->assertCount(3, $this->postedFor($admin), 'the administrator hears everything');
    }

    public function test_webhooks_go_only_to_the_services_own_hosts(): void
    {
        foreach (['https://hooks.slack.com.evil.cz/services/x', 'http://127.0.0.1:11334/learnspam', 'https://hooks.slack.com/services/../../x'] as $url) {
            try {
                NotificationChannelTable::config(['type' => 'slack', 'url' => $url]);
                $this->fail("{$url} must be refused");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['url' => 'https://discord.com/api/webhooks/123456789/abcdefghijklmnopqrstuvwx'],
            NotificationChannelTable::config(['type' => 'discord', 'url' => 'https://discord.com/api/webhooks/123456789/abcdefghijklmnopqrstuvwx']));
    }

    public function test_each_user_sees_only_their_own_channels(): void
    {
        $mine = User::factory()->create();
        $other = User::factory()->create();
        $this->slack($other);
        $this->actingAs($mine);

        $this->assertSame(0, NotificationChannel::query()->count());
        $this->get('/notification-channels')->assertOk()->assertDontSee('tým');
    }
}
