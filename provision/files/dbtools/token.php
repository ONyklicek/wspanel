<?php
// WSPanel: consume a one-time sign-on token written by the agent.
// Tokens are 64 hex chars, valid 60 s, deleted on first use.
function wspanel_consume_token(string $tool): array
{
    $token = $_POST['token'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
        wspanel_deny();
    }
    $file = '/run/wspanel-dbtools/'.$token.'.json';
    $raw = @file_get_contents($file);
    @unlink($file);
    $data = $raw === false ? null : json_decode($raw, true);
    if (! is_array($data) || ($data['tool'] ?? '') !== $tool || ($data['expires'] ?? 0) < time()) {
        wspanel_deny();
    }

    return $data;
}

function wspanel_deny(): never
{
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>WSPanel</title><p style="font-family:system-ui;margin:3rem">Přístup jen z panelu: otevřete databázi znovu ze stránky Databáze.</p>';
    exit;
}
