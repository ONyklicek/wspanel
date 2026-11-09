<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * A site's environment as Prostředí shows it (like GitHub's variables and
 * secrets): variables with their values, secrets by name only, both in the
 * site's shared env file. The panel keeps no secret value: it names which
 * keys are secret and the agent never hands their values out.
 */
class ManageEnvironment
{
    public const KEY_RULE = '/^[A-Za-z_][A-Za-z0-9_]{0,127}$/';

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @return array{vars: array<string, string>, secrets: array<string, bool>, managed: list<string>, panel_set: list<string>}
     *
     * @throws AgentException
     */
    public function list(Site $site): array
    {
        $secretKeys = $this->secretKeys($site);
        $out = $this->agent->call('env.vars', ['domain' => $site->domain, 'secrets' => $secretKeys]);
        $vars = $secrets = [];
        foreach ($out['vars'] ?? [] as $k => $v) {
            // A value the agent masked is a secret whatever the panel remembered.
            is_array($v) || in_array($k, $secretKeys, true) ? $secrets[$k] = is_array($v) ? (bool) ($v['set'] ?? false) : $v !== '' : $vars[$k] = (string) $v;
        }
        // A secret the file no longer has (removed by hand) is still listed as unset.
        foreach ($secretKeys as $k) {
            $secrets[$k] ??= false;
        }
        ksort($vars);
        ksort($secrets);

        return ['vars' => $vars, 'secrets' => $secrets, 'managed' => $out['managed'] ?? [], 'panel_set' => $out['panel_set'] ?? []];
    }

    /** @return list<string> */
    public function secretKeys(Site $site): array
    {
        return array_values(array_filter((array) $site->env_secrets, 'is_string'));
    }

    /** A variable: its value is shown; turning a secret into a variable is refused. @throws AgentException */
    public function setVariable(Site $site, string $key, string $value): void
    {
        if (in_array($key, $this->secretKeys($site), true)) {
            throw new AgentException('invalid_params', __(':key je tajná hodnota: změňte ji mezi tajnými, nebo ji smažte a založte jako proměnnou.', ['key' => $key]), 'env.vars_set');
        }
        $this->agent->call('env.vars_set', ['domain' => $site->domain, 'vars' => [$key => $value]]);
    }

    /** A secret: written to the env file, never shown again. @throws AgentException */
    public function setSecret(Site $site, string $key, string $value): void
    {
        $this->agent->call('env.vars_set', ['domain' => $site->domain, 'secrets' => [$key => $value]]);
        $site->update(['env_secrets' => array_values(array_unique([...$this->secretKeys($site), $key]))]);
    }

    /** @throws AgentException */
    public function remove(Site $site, string $key): void
    {
        $this->agent->call('env.vars_set', ['domain' => $site->domain, 'unset' => [$key]]);
        $site->update(['env_secrets' => array_values(array_diff($this->secretKeys($site), [$key]))]);
    }

    /** Composer credential types (auth.json) the form offers. */
    public const COMPOSER_TYPES = [
        'http-basic' => 'HTTP Basic (jméno a heslo/token, např. repo.packagist.com, Satis)',
        'github-oauth' => 'GitHub token',
        'gitlab-token' => 'GitLab token',
        'bearer' => 'Bearer token',
    ];

    /**
     * The hosts Composer has credentials for (secrets never come back).
     *
     * @return list<array{type: string, host: string, username?: string}>
     *
     * @throws AgentException
     */
    public function composerAuth(Site $site): array
    {
        return $this->agent->call('site.composer_auth', ['domain' => $site->domain])['entries'] ?? [];
    }

    /** @throws AgentException */
    public function setComposerAuth(Site $site, string $type, string $host, string $secret, ?string $username = null): void
    {
        $this->agent->call('site.composer_auth_set', array_filter(['domain' => $site->domain, 'type' => $type, 'host' => $host, 'username' => $username, 'secret' => $secret], fn ($v) => $v !== null && $v !== ''));
    }

    /** @throws AgentException */
    public function removeComposerAuth(Site $site, string $type, string $host): void
    {
        $this->agent->call('site.composer_auth_set', ['domain' => $site->domain, 'type' => $type, 'host' => $host, 'remove' => true]);
    }
}
