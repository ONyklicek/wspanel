<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The few GitHub REST calls the panel makes for a connected account: who the
 * token belongs to, its repositories and branches, a read-only deploy key and
 * a webhook in a repository, an Actions secret, and deployments with their
 * statuses. Nothing else is done with the token.
 */
class GitHubClient
{
    public const API = 'https://api.github.com';

    /** Repository names: owner/name, as GitHub allows them. */
    public const REPO_PATTERN = '~^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})/[A-Za-z0-9._-]{1,100}$~';

    public function __construct(private readonly string $token) {}

    /** @return array{login: string, name: ?string} */
    public function user(): array
    {
        $u = $this->send('get', '/user');

        return ['login' => (string) $u['login'], 'name' => $u['name'] ?? null];
    }

    /**
     * Repositories the token can administer, most recently pushed first.
     *
     * @return array<string, string> full_name => label
     */
    public function repositories(int $pages = 3): array
    {
        $out = [];
        for ($page = 1; $page <= $pages; $page++) {
            $batch = $this->send('get', '/user/repos', ['per_page' => 100, 'page' => $page, 'sort' => 'pushed', 'affiliation' => 'owner,collaborator,organization_member']);
            foreach ($batch as $r) {
                if (($r['permissions']['admin'] ?? false) === true) {
                    $out[$r['full_name']] = $r['full_name'].($r['private'] ? ' 🔒' : '');
                }
            }
            if (count($batch) < 100) {
                break;
            }
        }

        return $out;
    }

    /** @return array{default_branch: string, private: bool, ssh_url: string} */
    public function repository(string $repo): array
    {
        $r = $this->send('get', "/repos/{$this->repo($repo)}");

        return ['default_branch' => (string) $r['default_branch'], 'private' => (bool) $r['private'], 'ssh_url' => (string) $r['ssh_url']];
    }

    /** @return list<string> */
    public function branches(string $repo): array
    {
        return array_column($this->send('get', "/repos/{$this->repo($repo)}/branches", ['per_page' => 100]), 'name');
    }

    /** Adds a read-only deploy key; returns its id. */
    public function addDeployKey(string $repo, string $title, string $publicKey): int
    {
        return (int) $this->send('post', "/repos/{$this->repo($repo)}/keys", ['title' => $title, 'key' => trim($publicKey), 'read_only' => true])['id'];
    }

    public function deleteDeployKey(string $repo, int $id): void
    {
        $this->send('delete', "/repos/{$this->repo($repo)}/keys/{$id}", allowMissing: true);
    }

    /** Creates a push webhook with a secret (JSON, signed); returns its id. */
    public function addWebhook(string $repo, string $url, string $secret): int
    {
        return (int) $this->send('post', "/repos/{$this->repo($repo)}/hooks", [
            'name' => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret, 'insecure_ssl' => '0'],
        ])['id'];
    }

    public function deleteWebhook(string $repo, int $id): void
    {
        $this->send('delete', "/repos/{$this->repo($repo)}/hooks/{$id}", allowMissing: true);
    }

    /** Stores an Actions secret, sealed with the repository's public key. */
    public function putActionsSecret(string $repo, string $name, string $value): void
    {
        $key = $this->send('get', "/repos/{$this->repo($repo)}/actions/secrets/public-key");
        $sealed = sodium_crypto_box_seal($value, base64_decode((string) $key['key'], true) ?: '');
        $this->send('put', "/repos/{$this->repo($repo)}/actions/secrets/{$name}", ['encrypted_value' => base64_encode($sealed), 'key_id' => $key['key_id']]);
    }

    /** Creates a deployment of a commit or ref; returns its id. */
    public function createDeployment(string $repo, string $ref, string $environment, string $description): int
    {
        return (int) $this->send('post', "/repos/{$this->repo($repo)}/deployments", [
            'ref' => $ref,
            'environment' => $environment,
            'description' => mb_substr($description, 0, 140),
            'auto_merge' => false,
            'required_contexts' => [], // the panel deploys what it was given; checks ran before
            'production_environment' => $environment === 'production',
            'transient_environment' => false,
        ])['id'];
    }

    /** @param 'in_progress'|'success'|'failure'|'error' $state */
    public function deploymentStatus(string $repo, int $deployment, string $state, string $environmentUrl, string $logUrl, string $description): void
    {
        $this->send('post', "/repos/{$this->repo($repo)}/deployments/{$deployment}/statuses", [
            'state' => $state,
            'environment_url' => $environmentUrl,
            'log_url' => $logUrl,
            'description' => mb_substr($description, 0, 140),
            'auto_inactive' => true,
        ]);
    }

    private function repo(string $repo): string
    {
        if (! preg_match(self::REPO_PATTERN, $repo)) {
            throw new GitHubException(__('Neplatný název repozitáře: :repo', ['repo' => $repo]));
        }

        return $repo;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    private function send(string $method, string $path, array $data = [], bool $allowMissing = false): array
    {
        try {
            $response = $this->http()->{$method}($path, $data);
        } catch (ConnectionException $e) {
            throw new GitHubException(__('GitHub není dostupný: :error', ['error' => $e->getMessage()]));
        }
        if ($allowMissing && $response->status() === 404) {
            return [];
        }
        if ($response->failed()) {
            throw new GitHubException($this->message($response), $response->status());
        }

        return (array) ($response->json() ?? []);
    }

    private function message(Response $response): string
    {
        $msg = (string) ($response->json('message') ?? 'HTTP '.$response->status());
        $detail = collect((array) $response->json('errors'))->map(fn ($e) => is_array($e) ? ($e['message'] ?? $e['code'] ?? '') : (string) $e)->filter()->join('; ');

        return match ($response->status()) {
            401 => __('GitHub odmítl token (vypršel nebo byl zrušen).'),
            403, 404 => __('GitHub: :message. Token nemá přístup k repozitáři nebo mu chybí oprávnění (Administration, Webhooks, Deployments, Secrets).', ['message' => $msg]),
            default => trim("GitHub: {$msg} {$detail}"),
        };
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::API)
            ->withToken($this->token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'WSPanel'])
            ->timeout(15)
            ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false);
    }
}
