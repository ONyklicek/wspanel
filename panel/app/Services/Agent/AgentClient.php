<?php

namespace App\Services\Agent;

/**
 * Talks to panel-agent over its Unix socket: one JSON request per connection.
 * The panel never executes system commands itself — everything privileged
 * goes through the agent's whitelist.
 */
class AgentClient
{
    public function __construct(
        private readonly string $socket,
        private readonly int $timeout = 300,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|list<mixed>
     *
     * @throws AgentException
     */
    public function call(string $op, array $params = []): array
    {
        $errno = 0;
        $errstr = '';
        $conn = @stream_socket_client('unix://'.$this->socket, $errno, $errstr, 5);
        if ($conn === false) {
            throw new AgentException('unreachable', "Agent není dostupný ({$this->socket}): {$errstr}", $op);
        }

        try {
            stream_set_timeout($conn, $this->timeout);
            $payload = json_encode(
                ['op' => $op, 'params' => (object) $params],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
            fwrite($conn, $payload."\n");

            $line = fgets($conn);
            if ($line === false) {
                $meta = stream_get_meta_data($conn);
                throw new AgentException(
                    $meta['timed_out'] ? 'timeout' : 'no_response',
                    $meta['timed_out'] ? 'Agent neodpověděl včas.' : 'Agent ukončil spojení bez odpovědi.',
                    $op,
                );
            }
        } finally {
            fclose($conn);
        }

        $response = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! ($response['ok'] ?? false)) {
            throw new AgentException(
                $response['error']['code'] ?? 'unknown',
                $response['error']['message'] ?? 'Neznámá chyba agenta.',
                $op,
            );
        }

        return $response['output'] ?? [];
    }
}
