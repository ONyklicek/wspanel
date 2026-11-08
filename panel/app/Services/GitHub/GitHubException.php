<?php

namespace App\Services\GitHub;

use RuntimeException;

/** A GitHub API call that failed, with GitHub's own message and status. */
final class GitHubException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }
}
