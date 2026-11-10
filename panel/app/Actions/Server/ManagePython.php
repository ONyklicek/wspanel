<?php

namespace App\Actions\Server;

use App\Models\AgentTask;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
use App\Support\Lang;
use Illuminate\Support\Facades\Cache;

/**
 * Python for Python sites: not on a server until asked for (Server → Moduly
 * or install.sh --python=). The agent brings uv and the chosen CPython build.
 */
class ManagePython
{
    public function __construct(private readonly AgentClient $agent) {}

    /** @throws AgentException */
    public function install(string $version): AgentTask
    {
        $job = $this->agent->call('python.install', ['version' => $version]);

        return AgentTasks::start($job, 'python.install', [
            'title' => __('Instalace Pythonu :version', ['version' => $version]),
            'done' => __('Python :version nainstalován', ['version' => $version]),
            'body' => __('Při zakládání webu zvolte režim Python.'),
        ], self::class.'@versionsChanged', key: 'python');
    }

    public function versionsChanged(AgentTask $task): void
    {
        Cache::forget('agent.sys.versions');
    }

    /**
     * Moves an installed minor version to its newest patch release (security
     * fixes). The agent restarts the sites on it onto the new interpreter and
     * removes the old build.
     *
     * Runs in the background; the task's output: from, to, changed, restarted.
     *
     * @throws AgentException
     */
    public function upgrade(string $version): AgentTask
    {
        $job = $this->agent->call('python.upgrade', ['version' => $version]);

        return AgentTasks::start($job, 'python.upgrade', [
            'title' => __('Aktualizace Pythonu :version', ['version' => $version]),
            'done' => __('Python :version je aktuální', ['version' => $version]),
        ], self::class.'@upgraded', key: 'python');
    }

    /** What the upgrade did, for the message. */
    public function upgraded(AgentTask $task): void
    {
        Cache::forget('agent.sys.versions');
        $out = (array) $task->output;
        if ($task->status === AgentTask::DONE && ($out['changed'] ?? false)) {
            $restarted = (array) ($out['restarted'] ?? []);
            $task->update([
                'done_title' => __('Python aktualizován na :version', ['version' => $out['to'] ?? '']),
                'body' => $restarted === [] ? __('Žádný web na této verzi neběží.')
                    : __('Restartované služby:').' '.Lang::count(count($restarted), ':count služba', ':count služby', ':count služeb'),
            ]);
        }
    }
}
