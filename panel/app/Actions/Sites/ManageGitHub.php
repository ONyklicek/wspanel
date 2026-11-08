<?php

namespace App\Actions\Sites;

use App\Models\GithubConnection;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentException;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Deploys from GitHub without copying anything by hand: a connected account
 * (a token) picks the repository and branch, and the panel adds the site's
 * read-only deploy key, points the deploy at the repository, creates the
 * webhook (or watches the repository when the panel is not public) and,
 * on request, stores the CI token as an Actions secret.
 */
class ManageGitHub
{
    public const ENVIRONMENT = 'production';

    public function __construct(
        private readonly ManageDeploys $deploys,
        private readonly ManageWebhook $webhook,
        private readonly ManageCiToken $ciToken,
    ) {}

    /** Checks the token with GitHub and stores it (one connection per account and user). */
    public function connect(User $user, string $token): GithubConnection
    {
        $who = (new GitHubClient(trim($token)))->user();

        return GithubConnection::query()->withoutGlobalScope('owner')->updateOrCreate(
            ['user_id' => $user->id, 'login' => $who['login']],
            ['token' => trim($token), 'last_used_at' => now()],
        );
    }

    /** Unlinks the account's sites (removing what the panel created on GitHub) and forgets the token. */
    public function disconnect(GithubConnection $connection): void
    {
        foreach ($connection->sites()->get() as $site) {
            $this->unlink($site);
        }
        $connection->delete();
    }

    /**
     * Repositories the account administers, cached briefly (a modal re-renders often).
     *
     * @return array<string, string>
     */
    public function repositories(GithubConnection $connection): array
    {
        return Cache::remember("github.repos.{$connection->id}", 300, fn () => $connection->client()->repositories());
    }

    /** @return array<string, string> */
    public function branches(GithubConnection $connection, string $repo): array
    {
        if (! preg_match(GitHubClient::REPO_PATTERN, $repo)) {
            return [];
        }
        $names = Cache::remember("github.branches.{$connection->id}.{$repo}", 120, fn () => $connection->client()->branches($repo));

        return array_combine($names, $names);
    }

    /**
     * @param  array{repo: string, branch: string, trigger: 'branch'|'tags', notify: 'webhook'|'poll', ci_secret?: bool, environment?: string}  $data
     * @return array{trigger: string, ci_secret: bool}
     *
     * @throws GitHubException|AgentException
     */
    public function link(Site $site, GithubConnection $connection, array $data): array
    {
        $gh = $connection->client();
        $repo = $gh->repository($data['repo']); // access check before anything changes
        if ($site->github_repo !== null) {
            $this->unlink($site);
        }

        $created = [];
        try {
            $keyId = $gh->addDeployKey($data['repo'], 'WSPanel '.$site->domain, $this->deploys->publicKey($site));
            $created[] = fn () => $gh->deleteDeployKey($data['repo'], $keyId);

            $config = $this->deploys->history($site)['config'] ?? null;
            $this->deploys->configure($site, ['repo' => $repo['ssh_url'], 'branch' => $data['branch']] + ($config ?? [])
                + ['migrate' => true, 'assets' => true, 'auto_rollback' => true, 'keep' => 5]);

            $hookId = null;
            if ($data['notify'] === 'webhook') {
                $site = $this->webhook->enable($site);
                $hookId = $gh->addWebhook($data['repo'], (string) ManageWebhook::url($site), (string) $site->webhook_secret);
                $created[] = fn () => $gh->deleteWebhook($data['repo'], $hookId);
                $trigger = $data['trigger'];
            } else {
                $site = $this->webhook->enable($site);
                $trigger = $data['trigger'] === 'tags' ? 'poll_tags' : 'poll_branch';
            }

            $ciSecret = (bool) ($data['ci_secret'] ?? false);
            if ($ciSecret) {
                $gh->putActionsSecret($data['repo'], 'WSPANEL_TOKEN', $this->ciToken->generate($site));
            }
        } catch (Throwable $e) {
            foreach (array_reverse($created) as $undo) {
                rescue($undo, report: false);
            }
            throw $e;
        }

        $site->update([
            'github_connection_id' => $connection->id,
            'github_repo' => $data['repo'],
            'github_key_id' => $keyId,
            'github_hook_id' => $hookId,
            'github_environment' => $data['environment'] ?? self::ENVIRONMENT,
            'github_linked_at' => now(),
            'deploy_trigger' => $trigger,
            'deploy_polled_ref' => null,
            'deploy_polled_at' => null,
            'deploy_poll_error' => null,
        ]);
        $connection->update(['last_used_at' => now()]);

        return ['trigger' => $trigger, 'ci_secret' => $ciSecret];
    }

    /**
     * Removes the webhook and deploy key the panel created (best effort: a
     * revoked token must not keep the site linked) and stops automatic deploys
     * that relied on the webhook. The deploy settings stay.
     *
     * @return list<string> what could not be removed on GitHub
     */
    public function unlink(Site $site): array
    {
        $problems = [];
        $connection = $site->githubConnection;
        if ($connection !== null && $site->github_repo !== null) {
            $gh = $connection->client();
            foreach (['github_hook_id' => 'deleteWebhook', 'github_key_id' => 'deleteDeployKey'] as $column => $call) {
                if ($site->{$column} !== null) {
                    try {
                        $gh->{$call}($site->github_repo, (int) $site->{$column});
                    } catch (GitHubException $e) {
                        $problems[] = $e->getMessage();
                    }
                }
            }
        }
        if ($site->github_hook_id !== null) {
            $this->webhook->disable($site);
        }
        $site->update(['github_connection_id' => null, 'github_repo' => null, 'github_hook_id' => null, 'github_key_id' => null, 'github_environment' => null, 'github_linked_at' => null]);

        return $problems;
    }
}
