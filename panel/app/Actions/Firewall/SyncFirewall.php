<?php

namespace App\Actions\Firewall;

use App\Models\FirewallRule;
use App\Services\Agent\AgentClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Mirrors `ufw status` into firewall_rules, keeping panel-side descriptions. */
class SyncFirewall
{
    public function __construct(private readonly AgentClient $agent) {}

    /** @return array{active: bool, rules: int} */
    public function handle(): array
    {
        $out = $this->agent->call('fw.list');
        $now = now();

        DB::transaction(function () use ($out, $now) {
            $keep = [];
            foreach ($out['rules'] as $r) {
                $rule = FirewallRule::updateOrCreate(
                    ['to' => $r['to'], 'action' => $r['action'], 'from' => $r['from']],
                    [
                        'port' => $r['port'] ?? null,
                        'proto' => ($r['proto'] ?? '') ?: null,
                        'comment' => ($r['comment'] ?? '') ?: null,
                        'ipv4' => $r['ipv4'],
                        'ipv6' => $r['ipv6'],
                        'managed' => $r['managed'],
                        'synced_at' => $now,
                    ],
                );
                $keep[] = $rule->id;
            }
            FirewallRule::query()->whereNotIn('id', $keep)->delete();
        });

        Cache::forever('firewall.status', [
            'active' => (bool) $out['active'],
            'protected_ports' => $out['protected_ports'] ?? [],
            'synced_at' => $now->toIso8601String(),
        ]);

        return ['active' => (bool) $out['active'], 'rules' => count($out['rules'])];
    }
}
