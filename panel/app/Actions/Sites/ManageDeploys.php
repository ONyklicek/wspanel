<?php

namespace App\Actions\Sites;

use App\Models\Deployment;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Carbon;

/** Deploys run in the agent (as the site user, sandboxed); the panel starts them and shows the log. */
class ManageDeploys
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @return array{deploys: list<array<string, mixed>>, releases: list<string>, current: string, config: ?array<string, mixed>, running: bool}
     *
     * @throws AgentException
     */
    public function history(Site $site): array
    {
        $out = $this->agent->call('deploy.history', ['domain' => $site->domain]);
        $out['deploys'] ??= [];
        foreach ($out['deploys'] as $d) {
            Deployment::updateOrCreate(['site_id' => $site->id, 'release' => $d['id']], [
                'status' => $d['status'],
                'commit' => ($d['commit'] ?? '') ?: null,
                'ref' => ($d['ref'] ?? '') ?: null,
                'source' => ($d['source'] ?? '') ?: null,
                'error' => $d['error'] ?? null,
                'started_at' => Carbon::parse($d['started']),
                'finished_at' => isset($d['finished']) ? Carbon::parse($d['finished']) : null,
            ]);
        }

        return $out;
    }

    /**
     * @param  array{repo: string, branch: string, migrate: bool, assets: bool, auto_rollback: bool, keep: int|string}  $config
     *
     * @throws AgentException
     */
    public function configure(Site $site, array $config): void
    {
        $this->agent->call('deploy.config', [
            'domain' => $site->domain,
            'repo' => trim((string) ($config['repo'] ?? '')),
            'branch' => trim($config['branch']),
            'migrate' => (bool) $config['migrate'],
            'assets' => (bool) $config['assets'],
            'auto_rollback' => (bool) $config['auto_rollback'],
            'keep' => (int) $config['keep'],
            'commands' => self::commandLines($config['commands'] ?? []),
            'env_from_deploy' => (bool) ($config['env_from_deploy'] ?? false),
            'maintenance' => (bool) ($config['maintenance'] ?? false),
        ]);
    }

    /**
     * Console commands after the deploy, one per line (artisan / bin/console
     * prefix optional).
     *
     * @param  string|list<string>  $commands
     * @return list<string>
     */
    public static function commandLines(string|array $commands): array
    {
        $lines = is_array($commands) ? $commands : (preg_split('/\R/', $commands) ?: []);

        return array_values(array_filter(array_map(
            fn ($line) => trim((string) preg_replace('~^(php\s+)?(artisan|bin/console)\s+~', '', trim((string) $line))),
            $lines,
        ), fn ($line) => $line !== ''));
    }

    /** @throws AgentException */
    public function opcache(Site $site, bool $production): void
    {
        $this->agent->call('site.opcache', ['domain' => $site->domain, 'production' => $production]);
    }

    /** @throws AgentException */
    public function publicKey(Site $site): string
    {
        return $this->agent->call('deploy.key', ['domain' => $site->domain])['public_key'];
    }

    /**
     * Starts a deploy; returns the release id once the agent has registered it.
     *
     * @throws AgentException
     */
    /**
     * Starts a deploy and returns its release id.
     *
     * @param  array{ref?: string, artifact?: string, commit?: string}  $options
     */
    public function start(Site $site, array $options = []): ?string
    {
        $this->agent->call('deploy.run', ['domain' => $site->domain] + array_filter($options));
        for ($i = 0; $i < 20; $i++) { // the release id appears in the history as soon as the job starts
            $running = collect($this->history($site)['deploys'])->firstWhere('status', 'running');
            if ($running) {
                return $running['id'];
            }
            usleep(150_000);
        }

        return Deployment::query()->where('site_id', $site->id)->latest('started_at')->value('release');
    }

    /**
     * @return array{content: string, offset: int, status: string}
     *
     * @throws AgentException
     */
    public function log(Site $site, string $release, int $offset): array
    {
        return $this->agent->call('deploy.log', ['domain' => $site->domain, 'id' => $release, 'offset' => $offset]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function rollback(Site $site, ?string $release = null): array
    {
        return $this->agent->call('deploy.rollback', array_filter(['domain' => $site->domain, 'release' => $release]));
    }

    /** @throws AgentException */
    public function envGet(Site $site): string
    {
        return $this->agent->call('env.get', ['domain' => $site->domain])['content'];
    }

    /** @throws AgentException */
    public function envSet(Site $site, string $content): void
    {
        $this->agent->call('env.set', ['domain' => $site->domain, 'content' => str_replace("\r\n", "\n", $content)]);
    }
}
