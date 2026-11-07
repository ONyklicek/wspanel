<?php

namespace App\Exceptions;

use App\Services\Agent\AgentException;

/**
 * A client's package does not allow this. Raised by the panel before the agent
 * is asked, and an AgentException on purpose: every screen already turns one
 * into a readable error, so a refused limit is shown exactly like a refused op.
 */
final class LimitReachedException extends AgentException
{
    public static function make(string $message): self
    {
        return new self('limit_reached', $message, 'panel');
    }
}
