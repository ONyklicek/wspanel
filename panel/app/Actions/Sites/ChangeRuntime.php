<?php

namespace App\Actions\Sites;

use App\Actions\Services\SyncServices;
use App\Models\AgentTask;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
use App\Support\Domains;
use App\Support\Limits;

/** Mode and version changes. The agent builds the new backend before removing the old one. */
class ChangeRuntime
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly SyncServices $sync,
    ) {}

    /**
     * @param  array<string, mixed>  $data  mode, framework, php_version / node_version
     *
     * @throws AgentException
     */
    public function switchMode(Site $site, array $data): Site
    {
        app(Limits::class)->assertMode($site->client, (string) ($data['mode'] ?? $site->mode->value), ($data['mode'] ?? null) === 'classic' ? ($data['php_version'] ?? null) : null);
        $out = $this->agent->call('site.switch_mode', ['domain' => $site->domain] + Domains::runtimeParams($data));

        return $this->record($site, $out);
    }

    /**
     * The switch in the background (the queue worker): the agent builds the
     * new backend and waits for it to answer before the proxy moves. The
     * package's limits are checked here, so a refusal shows in the modal.
     *
     * @param  array<string, mixed>  $data
     */
    public function queueSwitchMode(Site $site, array $data): AgentTask
    {
        app(Limits::class)->assertMode($site->client, (string) ($data['mode'] ?? $site->mode->value), ($data['mode'] ?? null) === 'classic' ? ($data['php_version'] ?? null) : null);

        return AgentTasks::queue('site.switch_mode', [
            'title' => __('Změna režimu webu :domain', ['domain' => $site->domain]),
            'done' => __(':domain: režim změněn', ['domain' => $site->domain]),
        ], self::class, 'switchModeQueued', [$site, $data], $site);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{body: string}
     *
     * @throws AgentException
     */
    public function switchModeQueued(Site $site, array $data): array
    {
        return ['body' => $this->switchMode($site, $data)->runtimeLabel()];
    }

    /** @throws AgentException */
    public function setVersion(Site $site, string $version): Site
    {
        if ($site->mode->value === 'classic') {
            app(Limits::class)->assertMode($site->client, $site->mode, $version);
        }
        $out = $this->agent->call('site.set_version', ['domain' => $site->domain, 'version' => $version]);

        return $this->record($site, $out);
    }

    /**
     * The version change in the background (the queue worker): a new FPM pool
     * or a restarted process. The package's limits are checked here, so a
     * refusal shows in the modal.
     */
    public function queueSetVersion(Site $site, string $version): AgentTask
    {
        if ($site->mode->value === 'classic') {
            app(Limits::class)->assertMode($site->client, $site->mode, $version);
        }

        return AgentTasks::queue('site.set_version', [
            'title' => __('Změna verze webu :domain', ['domain' => $site->domain]),
            'done' => __(':domain: verze změněna', ['domain' => $site->domain]),
        ], self::class, 'setVersionQueued', [$site, $version], $site);
    }

    /**
     * @return array{body: string}
     *
     * @throws AgentException
     */
    public function setVersionQueued(Site $site, string $version): array
    {
        return ['body' => $this->setVersion($site, $version)->runtimeLabel()];
    }

    /** @param array<string, mixed> $out */
    private function record(Site $site, array $out): Site
    {
        $site->update(Site::attributesFromAgent($out) + ['health_checked_at' => null, 'health_ok' => null]);
        rescue(fn () => $this->sync->handle(), report: false);

        return $site;
    }
}
