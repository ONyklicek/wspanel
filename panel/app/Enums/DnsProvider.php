<?php

namespace App\Enums;

use NyonCode\WireCore\Foundation\Contracts\Enum\HasLabel;

enum DnsProvider: string implements HasLabel
{
    case Local = 'local';
    case Cloudflare = 'cloudflare';

    public function getLabel(): string
    {
        return match ($this) {
            self::Local => __('Tento server (PowerDNS)'),
            self::Cloudflare => 'Cloudflare',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Local => __('Server je autoritativní DNS pro doménu. U registrátora nastavíte jako nameservery tento server.'),
            self::Cloudflare => __('Zóna zůstává u Cloudflare, panel ji upravuje přes API token (oprávnění Zone:Read a DNS:Edit).'),
        };
    }
}
