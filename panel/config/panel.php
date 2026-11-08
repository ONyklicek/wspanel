<?php

return [
    // How site users reach SSH/SFTP (shown in the panel). Host defaults to the
    // host the panel is opened on; the port is the public SSH port (in the dev
    // container 22 is published as 2222).
    'ssh_host' => env('PANEL_SSH_HOST'),
    'ssh_port' => (int) env('PANEL_SSH_PORT', 22),

    // Public addresses of the server, used for suggested DNS records (A/AAAA, SPF).
    'public_ipv4' => env('PANEL_PUBLIC_IPV4'),
    // The release this server was installed from (written by the installer)
    // and where newer ones are published (owner/name on GitHub). A private
    // repository needs a token that may read it to see them.
    'version' => env('PANEL_VERSION'),
    'release_repo' => env('PANEL_RELEASE_REPO'),
    'release_token' => env('PANEL_RELEASE_TOKEN'),
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

    // Key the webmail proves itself with when a mailbox changes its password
    // (written by the mail hosting install, readable by the webmail and the panel).
    'webmail_api_key_file' => env('PANEL_WEBMAIL_API_KEY_FILE', '/srv/panel/shared/webmail-api.key'),

    // Mailpit web UI path on the panel domain (behind the panel login).
    'mailpit_path' => '/mailpit/',
];
