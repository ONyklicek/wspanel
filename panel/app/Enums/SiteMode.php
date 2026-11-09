<?php

namespace App\Enums;

use NyonCode\WireCore\Foundation\Contracts\Enum\HasLabel;

enum SiteMode: string implements HasLabel
{
    case Classic = 'classic';
    case Worker = 'worker';
    case Node = 'node';
    case Python = 'python';

    public function getLabel(): string
    {
        return match ($this) {
            self::Classic => __('Classic (PHP-FPM)'),
            self::Worker => __('Worker (FrankenPHP)'),
            self::Node => __('Node SSR'),
            self::Python => __('Python'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Classic => __('Kompatibilní režim, volitelná verze PHP. Pro běžné weby a starší aplikace.'),
            self::Worker => __('Aplikace zůstává v paměti mezi požadavky (Laravel Octane, Symfony 7.4+). Musí být worker-safe.'),
            self::Node => __('Server-side rendering (Nuxt, Next.js, vlastní server.js) jako dlouho běžící proces.'),
            self::Python => __('Django, Flask nebo FastAPI pod gunicornem / uvicornem, každý release s vlastním virtualenvem.'),
        };
    }

    /** The site is its own process on an agent-allocated port (no PHP). */
    public function isApp(): bool
    {
        return $this === self::Node || $this === self::Python;
    }
}
