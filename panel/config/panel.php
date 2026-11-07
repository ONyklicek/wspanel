<?php

return [
    // How site users reach SSH/SFTP (shown in the panel). Host defaults to the
    // host the panel is opened on; the port is the public SSH port (in the dev
    // container 22 is published as 2222).
    'ssh_host' => env('PANEL_SSH_HOST'),
    'ssh_port' => (int) env('PANEL_SSH_PORT', 22),

    // Public addresses of the server, used for suggested DNS records (A/AAAA, SPF).
    'public_ipv4' => env('PANEL_PUBLIC_IPV4'),
    'public_ipv6' => env('PANEL_PUBLIC_IPV6'),

    // Mailpit web UI path on the panel domain (behind the panel login).
    'mailpit_path' => '/mailpit/',
];
