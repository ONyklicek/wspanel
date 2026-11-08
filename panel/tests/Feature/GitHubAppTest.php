<?php

namespace Tests\Feature;

use App\Actions\Sites\ManageGitHub;
use App\Models\GithubConnection;
use App\Models\Setting;
use App\Models\User;
use App\Services\GitHub\GitHubApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Connecting GitHub with a button: the panel's GitHub App, created from a manifest. */
class GitHubAppTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.github.com';

    private string $pem = '';

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://panel.localhost:8443']);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->pem);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
    }

    private function configureApp(): void
    {
        Setting::put(GitHubApp::SETTING, ['id' => 42, 'slug' => 'wspanel-test', 'client_id' => 'Iv1.abc', 'owner' => 'acme',
            'secret' => Crypt::encryptString(json_encode(['client_secret' => 'shh', 'pem' => $this->pem]))]);
    }

    public function test_the_administrator_sends_the_manifest_to_github(): void
    {
        $this->actingAs(User::factory()->create());

        $r = $this->get('/github/app/create?org=acme-org')->assertOk();

        $state = session('github.app_state');
        $r->assertSee('https://github.com/organizations/acme-org/settings/apps/new?state='.$state, false);
        preg_match('/name="manifest" value="([^"]+)"/', $r->getContent(), $m);
        $manifest = json_decode(html_entity_decode($m[1]), true);
        $this->assertSame(route('github.app.created'), $manifest['redirect_url']);
        $this->assertSame([route('github.callback')], $manifest['callback_urls']);
        $this->assertSame('write', $manifest['default_permissions']['administration']);
        $this->assertFalse($manifest['hook_attributes']['active'], 'the app needs no webhook of its own');
    }

    public function test_only_an_administrator_creates_the_app(): void
    {
        $this->actingAs(User::factory()->forClient()->create());

        $this->get('/github/app/create')->assertForbidden();
        $this->get('/github/app/created?code=abcdefgh&state=x')->assertForbidden();
    }

    public function test_the_credentials_github_returns_are_kept_encrypted(): void
    {
        Http::fake([self::API.'/app-manifests/abcdefgh123/conversions' => Http::response([
            'id' => 42, 'slug' => 'wspanel-panel', 'client_id' => 'Iv1.abc', 'client_secret' => 'shh-secret', 'pem' => $this->pem, 'owner' => ['login' => 'acme'],
        ], 201)]);
        $this->actingAs(User::factory()->create())->withSession(['github.app_state' => 'st4te']);

        $this->get('/github/app/created?code=abcdefgh123&state=st4te')->assertRedirect();

        $app = GitHubApp::configured();
        $this->assertSame(['wspanel-panel', 'Iv1.abc'], [$app->slug, $app->clientId]);
        $raw = (string) DB::table('settings')->where('key', GitHubApp::SETTING)->value('value');
        $this->assertStringNotContainsString('shh-secret', $raw);
        $this->assertStringNotContainsString('PRIVATE KEY', $raw);
    }

    public function test_a_return_without_this_sessions_state_is_refused(): void
    {
        Http::fake();
        $this->configureApp();
        $this->actingAs(User::factory()->create())->withSession(['github.app_state' => 'mine', 'github.connect_state' => 'mine']);

        $this->get('/github/app/created?code=abcdefgh123&state=forged')->assertForbidden();
        $this->get('/github/callback?code=abc&installation_id=7&state=forged')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_connect_sends_the_user_to_install_the_app(): void
    {
        $this->configureApp();
        $this->actingAs(User::factory()->forClient()->create());

        $r = $this->get('/github/connect');

        $r->assertRedirect('https://github.com/apps/wspanel-test/installations/new?state='.session('github.connect_state'));
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?client_id=Iv1.abc', $this->get('/github/connect?existing=1')->headers->get('Location'));
    }

    public function test_the_callback_connects_only_installations_github_confirms_are_the_users(): void
    {
        $this->configureApp();
        Http::fake([
            'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_user']),
            self::API.'/user/installations*' => Http::response(['installations' => [
                ['id' => 7, 'app_id' => 42, 'account' => ['login' => 'acme', 'type' => 'Organization']],
                ['id' => 8, 'app_id' => 99, 'account' => ['login' => 'other-app', 'type' => 'User']],
            ]]),
        ]);
        $user = User::factory()->forClient()->create();
        $this->actingAs($user)->withSession(['github.connect_state' => 'st']);

        $this->get('/github/callback?code=c0de&installation_id=7&setup_action=install&state=st')->assertRedirect();

        $c = GithubConnection::query()->sole();
        $this->assertSame([$user->id, 7, 'acme', null], [$c->user_id, $c->installation_id, $c->login, $c->token]);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://github.com/login/oauth/access_token' && $r['client_secret'] === 'shh' && $r['code'] === 'c0de');
    }

    public function test_an_installation_id_that_is_not_the_users_connects_nothing(): void
    {
        $this->configureApp();
        Http::fake([
            'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_user']),
            self::API.'/user/installations*' => Http::response(['installations' => [['id' => 7, 'app_id' => 42, 'account' => ['login' => 'acme', 'type' => 'User']]]]),
        ]);
        $this->actingAs(User::factory()->create())->withSession(['github.connect_state' => 'st']);

        $this->get('/github/callback?code=c0de&installation_id=1234&state=st')->assertRedirect();

        $this->assertSame(0, GithubConnection::query()->count());
    }

    public function test_api_calls_use_a_cached_installation_token_signed_by_the_app(): void
    {
        $this->configureApp();
        Http::fake([
            self::API.'/app/installations/7/access_tokens' => Http::response(['token' => 'ghs_installation', 'expires_at' => now()->addHour()->toIso8601String()], 201),
            self::API.'/installation/repositories*' => Http::response(['repositories' => [['full_name' => 'acme/app', 'private' => true]]]),
            self::API.'/repos/acme/app/branches*' => Http::response([['name' => 'main']]),
        ]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $c = GithubConnection::query()->create(['user_id' => $user->id, 'login' => 'acme', 'installation_id' => 7]);

        $this->assertSame(['acme/app' => 'acme/app 🔒'], app(ManageGitHub::class)->repositories($c));
        $c->client()->branches('acme/app');

        Http::assertSentCount(3); // one token for both calls
        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/access_tokens')) {
                return false;
            }
            [$h, $p, $s] = explode('.', substr($r->header('Authorization')[0], 7));
            $sig = base64_decode(strtr($s, '-_', '+/'));

            return openssl_verify("{$h}.{$p}", $sig, $this->publicKey, OPENSSL_ALGO_SHA256) === 1
                && json_decode(base64_decode(strtr($p, '-_', '+/')), true)['iss'] === '42';
        });
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/installation/repositories') && $r->hasHeader('Authorization', 'Bearer ghs_installation'));
    }

    public function test_disconnecting_uninstalls_the_app_from_the_account(): void
    {
        $this->configureApp();
        Http::fake([self::API.'/app/installations/7' => Http::response(null, 204)]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $c = GithubConnection::query()->create(['user_id' => $user->id, 'login' => 'acme', 'installation_id' => 7]);

        app(ManageGitHub::class)->disconnect($c);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::API.'/app/installations/7');
        $this->assertSame(0, GithubConnection::query()->count());
    }

    public function test_the_page_offers_the_button_once_the_app_exists(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/api-tokens')->assertOk()->assertSee('Nastavit připojení tlačítkem');

        $this->configureApp();
        $this->get('/api-tokens')->assertOk()->assertSee(route('github.connect'), false)->assertDontSee('Nastavit připojení tlačítkem');
    }
}
