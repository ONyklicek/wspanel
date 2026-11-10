<?php

namespace App\Services\GitHub;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * This panel's own GitHub App: created once from a manifest (one click and a
 * confirmation on GitHub, no keys copied), then every user connects their
 * account or organisation with a button and picks the repositories on
 * GitHub. API calls use short-lived installation tokens; the panel keeps no
 * long-lived token of anybody.
 */
class GitHubApp
{
    public const SETTING = 'github.app';

    /** What the app may do in the repositories it is installed on (the same as a token needs). */
    public const PERMISSIONS = [
        'administration' => 'write', // deploy keys
        'repository_hooks' => 'write', // the push webhook
        'deployments' => 'write',
        'secrets' => 'write', // WSPANEL_TOKEN for Actions
        'contents' => 'read', // branches
        'metadata' => 'read',
    ];

    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $privateKey,
        public readonly string $owner,
    ) {}

    public static function configured(): ?self
    {
        $s = Setting::get(self::SETTING);
        if (! is_array($s) || ! isset($s['id'], $s['secret'])) {
            return null;
        }
        $secret = json_decode(Crypt::decryptString($s['secret']), true);

        return new self((int) $s['id'], (string) $s['slug'], (string) $s['client_id'], (string) $secret['client_secret'], (string) $secret['pem'], (string) ($s['owner'] ?? ''));
    }

    /**
     * The manifest GitHub creates the app from. Callbacks go through the
     * browser, so a panel on an internal address works too; the app has no
     * webhook of its own (sites get repository webhooks or are watched), so
     * the manifest carries no hook_attributes at all.
     *
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        $url = rtrim((string) config('app.url'), '/');
        $host = (string) parse_url($url, PHP_URL_HOST);

        return [
            'name' => mb_substr('WSPanel '.$host, 0, 34),
            'url' => $url,
            'redirect_url' => route('github.app.created'),
            'callback_urls' => [route('github.callback')],
            'request_oauth_on_install' => true,
            'public' => true, // clients install it on their own accounts
            // No hook_attributes: GitHub validates its URL even with the webhook
            // off and refuses a panel on a local address ("Invalid GitHub App
            // configuration"). The app needs no webhook; sites have their own.
            'default_permissions' => self::PERMISSIONS,
            'default_events' => [],
        ];
    }

    /** Finishes the manifest flow: GitHub hands over the new app's credentials once. */
    public static function createFromCode(string $code): self
    {
        if (! preg_match('/^[A-Za-z0-9_-]{8,128}$/', $code)) {
            throw new GitHubException(__('Neplatný kód od GitHubu.'));
        }
        // GitHub answers this one call with 502–504 under load while the app
        // already exists; the code stays valid for an hour, so try a few times.
        $http = Http::baseUrl(GitHubClient::API)->withHeaders(self::headers())
            ->retry([1000, 3000, 6000], when: fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false);

        return self::fromConversion(self::send($http, 'post', "/app-manifests/{$code}/conversions"));
    }

    /**
     * Keeps the app from GitHub's answer to the manifest conversion (also
     * when that answer was fetched another way, e.g. `gh api` on a network
     * where GitHub refuses anonymous calls).
     *
     * @param  array<string, mixed>  $r
     */
    public static function fromConversion(array $r): self
    {
        foreach (['id', 'slug', 'client_id', 'client_secret', 'pem'] as $key) {
            if (empty($r[$key])) {
                throw new GitHubException(__('Odpověď GitHubu neobsahuje klíče aplikace (:key).', ['key' => $key]));
            }
        }

        return self::store(new self((int) $r['id'], (string) $r['slug'], (string) $r['client_id'], (string) $r['client_secret'], (string) $r['pem'], (string) ($r['owner']['login'] ?? '')), $r['html_url'] ?? null);
    }

    /**
     * An app that already exists on GitHub (the hand-over failed, or it was
     * made by hand): its ID, client ID, a client secret and a private key from
     * its settings. Checked with GitHub (GET /app signed by the key) before it
     * is kept.
     */
    public static function createFromCredentials(int $id, string $clientId, string $clientSecret, string $privateKey): self
    {
        if (openssl_pkey_get_private($privateKey) === false) {
            throw new GitHubException(__('Privátní klíč není platný klíč PEM (soubor .pem stažený z nastavení aplikace).'));
        }
        $probe = new self($id, '', $clientId, $clientSecret, $privateKey, '');
        $r = self::send($probe->asApp()->retry([1000, 3000], when: fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false), 'get', '/app');
        if ((string) ($r['client_id'] ?? '') !== $clientId) {
            throw new GitHubException(__('Client ID nepatří aplikaci s tímto ID.'));
        }

        return self::store(new self($id, (string) $r['slug'], $clientId, $clientSecret, $privateKey, (string) ($r['owner']['login'] ?? '')), $r['html_url'] ?? null);
    }

    private static function store(self $app, ?string $htmlUrl): self
    {
        Setting::put(self::SETTING, [
            'id' => $app->id, 'slug' => $app->slug, 'client_id' => $app->clientId, 'owner' => $app->owner,
            'html_url' => $htmlUrl,
            'secret' => Crypt::encryptString(json_encode(['client_secret' => $app->clientSecret, 'pem' => $app->privateKey])),
        ]);

        return $app;
    }

    public static function forget(): void
    {
        Setting::query()->whereKey(self::SETTING)->delete();
    }

    /** Where a user installs the app (picks the account and repositories). */
    public function installUrl(string $state): string
    {
        return "https://github.com/apps/{$this->slug}/installations/new?state=".urlencode($state);
    }

    /** Where a user who already installed it signs in, to connect those installations. */
    public function authorizeUrl(string $state): string
    {
        return 'https://github.com/login/oauth/authorize?'.http_build_query(['client_id' => $this->clientId, 'state' => $state, 'redirect_uri' => route('github.callback')]);
    }

    /** Exchanges the code from the callback for the user's short-lived token. */
    public function userToken(string $code): string
    {
        $r = self::send(Http::withHeaders(['Accept' => 'application/json']), 'post', 'https://github.com/login/oauth/access_token', [
            'client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'code' => $code, 'redirect_uri' => route('github.callback'),
        ]);
        if (empty($r['access_token'])) {
            throw new GitHubException(__('GitHub přihlášení nepotvrdil: :error', ['error' => $r['error_description'] ?? $r['error'] ?? '?']));
        }

        return (string) $r['access_token'];
    }

    /**
     * Installations of this app the signed-in GitHub user can access: the
     * proof that an installation id from the callback is theirs.
     *
     * @return array<int, array{login: string, type: string}>
     */
    public function userInstallations(string $userToken): array
    {
        $r = self::send(Http::baseUrl(GitHubClient::API)->withHeaders(self::headers())->withToken($userToken), 'get', '/user/installations', ['per_page' => 100]);
        $out = [];
        foreach ($r['installations'] ?? [] as $i) {
            if ((int) ($i['app_id'] ?? 0) === $this->id) {
                $out[(int) $i['id']] = ['login' => (string) $i['account']['login'], 'type' => (string) $i['account']['type']];
            }
        }

        return $out;
    }

    /**
     * Repositories of an installation in which the signed-in GitHub user is an
     * administrator. The installation token may manage every repository of the
     * installation; a member with read access must not gain more through the
     * panel than GitHub gives them.
     *
     * @return list<string> owner/name
     */
    public function administeredRepositories(string $userToken, int $installation, int $pages = 5): array
    {
        $http = Http::baseUrl(GitHubClient::API)->withHeaders(self::headers())->withToken($userToken);
        $out = [];
        for ($page = 1; $page <= $pages; $page++) {
            $batch = self::send($http, 'get', "/user/installations/{$installation}/repositories", ['per_page' => 100, 'page' => $page])['repositories'] ?? [];
            foreach ($batch as $r) {
                if (($r['permissions']['admin'] ?? false) === true) {
                    $out[] = (string) $r['full_name'];
                }
            }
            if (count($batch) < 100) {
                break;
            }
        }

        return $out;
    }

    /** A token for one installation, valid an hour; cached until shortly before it expires. */
    public function installationToken(int $installation): string
    {
        $key = "github.installation.{$this->id}.{$installation}";
        if (is_string($cached = Cache::get($key))) {
            return Crypt::decryptString($cached);
        }
        $r = self::send($this->asApp(), 'post', "/app/installations/{$installation}/access_tokens");
        Cache::put($key, Crypt::encryptString((string) $r['token']), now()->addMinutes(50));

        return (string) $r['token'];
    }

    /** A JWT signed with the app's private key (RS256), valid 9 minutes. */
    public function jwt(): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $data = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode(['iat' => time() - 60, 'exp' => time() + 540, 'iss' => (string) $this->id]));
        if (! openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new GitHubException(__('Klíč GitHub aplikace nejde použít.'));
        }

        return $data.'.'.$b64($signature);
    }

    private function asApp(): PendingRequest
    {
        return Http::baseUrl(GitHubClient::API)->withHeaders(self::headers())->withToken($this->jwt());
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'WSPanel'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    private static function send(PendingRequest $http, string $method, string $url, array $data = [], bool $allowMissing = false): array
    {
        try {
            // An empty array would go out as the JSON body "[]", which GitHub
            // refuses on POST ("[] is not a null or object"): no body at all.
            $response = $data === [] ? $http->timeout(15)->send(strtoupper($method), $url) : $http->timeout(15)->{$method}($url, $data);
        } catch (ConnectionException $e) {
            throw new GitHubException(__('GitHub není dostupný: :error', ['error' => $e->getMessage()]));
        }
        if ($allowMissing && $response->status() === 404) {
            return [];
        }
        if ($response->serverError()) {
            throw new GitHubException(__('GitHub je teď přetížený nebo nedostupný (HTTP :status). Zkuste to za chvíli znovu.', ['status' => $response->status()]), $response->status());
        }
        if ($response->failed()) {
            throw new GitHubException('GitHub: '.($response->json('message') ?? 'HTTP '.$response->status()), $response->status());
        }

        return (array) ($response->json() ?? []);
    }
}
