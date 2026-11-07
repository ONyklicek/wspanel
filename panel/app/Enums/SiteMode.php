<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum SiteMode: string implements HasDescription, HasLabel
{
    case Classic = 'classic';
    case Worker = 'worker';
    case Node = 'node';

    public function getLabel(): string
    {
        return match ($this) {
            self::Classic => 'Classic (PHP-FPM)',
            self::Worker => 'Worker (FrankenPHP)',
            self::Node => 'Node SSR',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Classic => 'Kompatibilní režim, volitelná verze PHP. Pro běžné weby a starší aplikace.',
            self::Worker => 'Aplikace zůstává v paměti mezi požadavky (Laravel Octane, Symfony 7.4+). Musí být worker-safe.',
            self::Node => 'Server-side rendering (Nuxt, Next.js, vlastní server.js) jako dlouho běžící proces.',
        };
    }
}
