<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FirewallRule extends Model
{
    protected $fillable = [
        'to', 'port', 'proto', 'action', 'from', 'comment',
        'ipv4', 'ipv6', 'managed', 'description', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'ipv4' => 'boolean',
            'ipv6' => 'boolean',
            'managed' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /** UFW can delete a rule by spec only when it has a single port and protocol. */
    public function isDeletable(): bool
    {
        return $this->port !== null && in_array($this->proto, ['tcp', 'udp'], true)
            && in_array($this->action, ['allow', 'deny'], true);
    }

    /** @return array<string, mixed> */
    public function agentParams(): array
    {
        $params = ['action' => $this->action, 'port' => $this->port, 'proto' => $this->proto];
        if (! in_array($this->from, ['Anywhere', ''], true)) {
            $params['from'] = $this->from;
        }

        return $params;
    }
}
