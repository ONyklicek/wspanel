<?php

return [
    // Unix socket of the privileged panel-agent. On a dev machine point this
    // at an agent running in dry-run mode (see README).
    'socket' => env('AGENT_SOCKET', '/run/panel-agent.sock'),

    // Seconds to wait for a response. Long operations will return job IDs.
    'timeout' => (int) env('AGENT_TIMEOUT', 300),

    // Where the agent writes database dumps (readable by the panel group, for downloads).
    'backup_dir' => env('AGENT_BACKUP_DIR', '/var/backups/wspanel'),
];
