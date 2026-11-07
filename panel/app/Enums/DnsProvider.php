<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum DnsProvider: string implements HasDescription, HasLabel
{
    case Local = 'local';
    case Cloudflare = 'cloudflare';

    public function getLabel(): string
    {
        return match ($this) {
            self::Local => 'Tento server (PowerDNS)',
            self::Cloudflare => 'Cloudflare',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Local => 'Server je autoritativní DNS pro doménu. U registrátora nastavíte jako nameservery tento server.',
            self::Cloudflare => 'Zóna zůstává u Cloudflare, panel ji upravuje přes API token (oprávnění Zone:Read a DNS:Edit).',
        };
    }
}
