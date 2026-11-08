<?php

namespace App\Actions\Security;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/** fail2ban's bans, through the agent. */
class ManageFail2ban
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @return array<string, list<string>> jail => banned addresses
     *
     * @throws AgentException
     */
    public function bans(): array
    {
        return $this->agent->call('fail2ban.status')['jails'] ?? [];
    }

    /** @throws AgentException */
    public function unban(string $ip): void
    {
        $this->agent->call('fail2ban.unban', ['ip' => $ip]);
    }
}
