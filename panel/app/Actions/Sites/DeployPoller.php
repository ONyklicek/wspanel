<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Str;

/**
 * Automatic deploy without an incoming webhook: the server asks the repository
 * (git ls-remote, as the site's user) whether the branch moved or a newer tag
 * appeared. For a panel that is not reachable from the internet.
 *
 * A deploy starts only when the remote changed since the last check AND
 * differs from what the last deploy took, so a manual rollback or a commit
 * that fails to build is not retried every minute.
 */
class DeployPoller
{
    public const TRIGGERS = ['poll_branch' => 'branch', 'poll_tags' => 'tags'];

    public function __construct(private readonly AgentClient $agent, private readonly DeployTrigger $trigger) {}

    /** Called every minute by panel:sync. Returns the number of deploys started or queued. */
    public function all(): int
    {
        $started = 0;
        $sites = Site::query()->where('webhook_enabled', true)->whereIn('deploy_trigger', array_keys(self::TRIGGERS))->get();
        foreach ($sites as $site) {
            if ($this->poll($site) !== null) {
                $started++;
            }
        }

        return $started;
    }

    /**
     * @return 'deployed'|'queued'|null null when nothing changed or the check failed
     */
    public function poll(Site $site): ?string
    {
        $kind = self::TRIGGERS[$site->deploy_trigger] ?? null;
        if ($kind === null || ! $site->webhook_enabled) {
            return null;
        }

        try {
            $remote = $this->agent->call('deploy.remote_ref', ['domain' => $site->domain, 'kind' => $kind]);
            $history = $this->agent->call('deploy.history', ['domain' => $site->domain]);
        } catch (AgentException $e) {
            $site->update(['deploy_polled_at' => now(), 'deploy_poll_error' => Str::limit($e->getMessage(), 490)]);

            return null;
        }

        // Branch: the commit is what changes. Tags: the newest tag's name.
        $seen = (string) ($kind === 'branch' ? ($remote['commit'] ?? '') : ($remote['ref'] ?? ''));
        $previous = $site->deploy_polled_ref;
        $site->update(['deploy_polled_at' => now(), 'deploy_poll_error' => null, 'deploy_polled_ref' => $seen ?: null]);

        if ($seen === '' || $seen === $previous) {
            return null;
        }

        $last = $history['deploys'][0] ?? null;
        $lastTook = $kind === 'branch' ? ($last['commit'] ?? null) : ($last['ref'] ?? null);
        if ($last !== null && $lastTook === $seen) {
            return null;
        }

        try {
            return $this->trigger->request($site, $history, $kind === 'tags' ? $seen : null);
        } catch (AgentException $e) {
            // Forget what was seen, so the next minute tries again.
            $site->update(['deploy_polled_ref' => $previous, 'deploy_poll_error' => Str::limit('Deploy se nespustil: '.$e->getMessage(), 490)]);

            return null;
        }
    }
}
