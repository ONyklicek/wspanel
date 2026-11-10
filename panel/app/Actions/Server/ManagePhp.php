<?php

namespace App\Actions\Server;

use App\Models\AgentTask;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
use Illuminate\Support\Facades\Cache;

/**
 * PHP versions on the server and their extensions (apt packages from
 * packages.sury.org), plus what FrankenPHP has built in.
 */
class ManagePhp
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @return array{versions: list<array{version: string, installed: bool, default: bool, extensions: list<string>, available: list<string>}>, installable: list<string>, frankenphp: array<string, mixed>}
     *
     * @throws AgentException
     */
    public function status(): array
    {
        return $this->agent->call('php.status');
    }

    /**
     * Installs or removes an extension (apt), in the background.
     *
     * @throws AgentException
     */
    public function extension(string $version, string $extension, string $action): AgentTask
    {
        $job = $this->agent->call('php.extension', ['version' => $version, 'extension' => $extension, 'action' => $action]);

        return AgentTasks::start($job, 'php.extension', [
            'title' => $action === 'install' ? __('PHP :version: instalace :extension', ['version' => $version, 'extension' => $extension])
                : __('PHP :version: odebrání :extension', ['version' => $version, 'extension' => $extension]),
            'done' => "PHP {$version}: {$extension} ".($action === 'install' ? __('nainstalováno') : __('odebráno')),
        ], key: 'php');
    }

    /** @throws AgentException */
    public function install(string $version): AgentTask
    {
        $job = $this->agent->call('php.install', ['version' => $version]);

        return AgentTasks::start($job, 'php.install', [
            'title' => __('Instalace PHP :version', ['version' => $version]),
            'done' => __('PHP :version nainstalováno', ['version' => $version]),
            'body' => __('Weby ho zvolí v Nastavení → Běh a PHP → Verze.'),
        ], self::class.'@versionsChanged', key: 'php');
    }

    public function versionsChanged(AgentTask $task): void
    {
        Cache::forget('agent.sys.versions');
    }
}
