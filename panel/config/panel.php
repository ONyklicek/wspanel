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

    // Hosting clients with packages and limits (optional, install.sh --clients=yes;
    // switched later on Server → Moduly).
    'clients' => (bool) env('PANEL_CLIENTS', false),

    // The panel's own HTTPS port (443 = it has its domain to itself) and the
    // port browsers reach the sites on (as published; 8443 in the dev container).
    'port' => (int) env('PANEL_PORT', 443),
    'sites_https_port' => (int) env('PANEL_SITES_HTTPS_PORT', 0),

    // Host name of the mail server (MX, IMAP/SMTP for clients); default: the panel's.
    'mail_host' => env('PANEL_MAIL_HOST'),

    // Mailpit web UI path on the panel domain (behind the panel login).
    'mailpit_path' => '/mailpit/',
];
