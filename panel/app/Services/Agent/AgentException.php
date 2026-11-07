<?php

namespace App\Services\Agent;

use RuntimeException;

class AgentException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $op = '',
    ) {
        parent::__construct($message);
    }
}
