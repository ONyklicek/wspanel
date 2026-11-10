<?php

namespace App\Actions\Dns;

use App\Enums\DnsProvider;
use App\Models\AgentTask;
use App\Models\DnsZone;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\AgentTasks;
use Illuminate\Support\Facades\Cache;

/**
 * The local DNS server (PowerDNS) as a module: added from Server → Moduly
 * (or install.sh --dns=powerdns), taken away there once it holds no zone.
 */
class ManageDnsServer
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly SystemInfo $system,
    ) {}

    public function installed(): bool
    {
        return (bool) rescue(fn () => $this->system->versions()['dns_local'] ?? false, false, report: false);
    }

    /** @throws AgentException */
    public function install(): AgentTask
    {
        $job = $this->agent->call('dns.install');

        return AgentTasks::start($job, 'dns.install', [
            'title' => __('Instalace DNS serveru (PowerDNS)'),
            'done' => __('DNS server nainstalován'),
            'body' => __('Zóny přidáte v menu Síť → DNS; u registrátora pak nastavte nameservery na tento server.'),
        ], self::class.'@changed', key: 'dns');
    }

    /**
     * Takes the DNS server away. Refused while a zone of this server is
     * left: the agent checks PowerDNS itself too (a zone added by hand).
     *
     * @throws AgentException
     */
    public function uninstall(): AgentTask
    {
        $zones = DnsZone::acrossClients()->where('provider', DnsProvider::Local)->orderBy('domain')->pluck('domain');
        if ($zones->isNotEmpty()) {
            throw new AgentException('conflict', __('Na tomto serveru jsou ještě DNS zóny (:zones): nejdřív je smažte (Síť → DNS).', ['zones' => $zones->implode(', ')]), 'panel');
        }
        $job = $this->agent->call('dns.uninstall');

        return AgentTasks::start($job, 'dns.uninstall', [
            'title' => __('Odinstalace DNS serveru'),
            'done' => __('DNS server odinstalován'),
            'body' => __('Port 53 je zavřený. Zóny jde dál spravovat v Cloudflare.'),
        ], self::class.'@changed', key: 'dns');
    }

    public function changed(AgentTask $task): void
    {
        Cache::forget('agent.sys.versions');
    }
}
