<?php

namespace App\Services\Agent;

use App\Support\AgentMessages;
use RuntimeException;

/**
 * An agent error. getMessage() is the Czech wording shown in the panel; the
 * agent's own (English) text stays in $raw for logs.
 */
class AgentException extends RuntimeException
{
    public readonly string $raw;

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $op = '',
    ) {
        $this->raw = $message;
        parent::__construct((string) AgentMessages::cs($message));
    }
}
