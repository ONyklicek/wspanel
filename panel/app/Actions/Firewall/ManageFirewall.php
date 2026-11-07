<?php

namespace App\Actions\Firewall;

use App\Models\FirewallRule;
use App\Services\Agent\AgentClient;

class ManageFirewall
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly SyncFirewall $sync,
    ) {}

    /**
     * @param  array{action: string, port: int|string, proto: string, from?: ?string, description?: ?string}  $data
     */
    public function add(array $data): void
    {
        $params = ['port' => (int) $data['port'], 'proto' => $data['proto']];
        if (filled($data['from'] ?? null)) {
            $params['from'] = trim($data['from']);
        }
        $out = $this->agent->call($data['action'] === 'deny' ? 'fw.deny' : 'fw.allow', $params);
        $this->sync->handle();

        if (filled($data['description'] ?? null)) {
            FirewallRule::query()
                ->where('action', $data['action'])
                ->where('port', $params['port'])
                ->where('proto', $params['proto'])
                ->where('from', filled($out['from'] ?? null) ? $out['from'] : 'Anywhere')
                ->update(['description' => $data['description']]);
        }
    }

    public function delete(FirewallRule $rule): void
    {
        $this->agent->call('fw.delete', $rule->agentParams());
        $this->sync->handle();
    }
}
