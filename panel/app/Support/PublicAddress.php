<?php

namespace App\Support;

/**
 * Whether the internet (a git host's webhook) can reach an address: not a
 * local name (localhost, .local, .lan, .internal, home.arpa) and not a
 * private or reserved IP. A public name may still be firewalled; this only
 * rules out what certainly cannot be reached.
 */
final class PublicAddress
{
    public static function host(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || preg_match('/(^|\.)(localhost|local|lan|internal|intranet|home\.arpa|test|example|invalid)$/', $host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return str_contains($host, '.');
    }

    public static function url(string $url): bool
    {
        return self::host((string) parse_url($url, PHP_URL_HOST));
    }
}
