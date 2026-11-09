<?php

namespace App\Enums;

use NyonCode\WireCore\Foundation\Contracts\Enum\HasLabel;

enum Framework: string implements HasLabel
{
    case Laravel = 'laravel';
    case Symfony = 'symfony';
    case Php = 'php';
    case Nuxt = 'nuxt';
    case Next = 'next';
    case Node = 'node';
    case Django = 'django';
    case Flask = 'flask';
    case FastApi = 'fastapi';
    case Python = 'python';

    public function getLabel(): string
    {
        return match ($this) {
            self::Laravel => 'Laravel',
            self::Symfony => 'Symfony',
            self::Php => __('Čisté PHP (bez frameworku)'),
            self::Nuxt => 'Nuxt',
            self::Next => 'Next.js',
            self::Node => __('Node (server.js)'),
            self::Django => 'Django',
            self::Flask => 'Flask',
            self::FastApi => 'FastAPI',
            self::Python => __('Python (app.py)'),
        };
    }

    public function isNode(): bool
    {
        return in_array($this, [self::Nuxt, self::Next, self::Node], true);
    }

    public function isPython(): bool
    {
        return in_array($this, [self::Django, self::Flask, self::FastApi, self::Python], true);
    }

    /** Django, Flask and FastAPI load a WSGI/ASGI module:object; plain Python runs app.py. */
    public function loadsPythonApp(): bool
    {
        return in_array($this, [self::Django, self::Flask, self::FastApi], true);
    }

    /** @return list<self> */
    public static function forMode(SiteMode $mode): array
    {
        return match ($mode) {
            SiteMode::Classic => [self::Laravel, self::Symfony, self::Php],
            SiteMode::Worker => [self::Laravel, self::Symfony],
            SiteMode::Node => [self::Nuxt, self::Next, self::Node],
            SiteMode::Python => [self::Django, self::Flask, self::FastApi, self::Python],
        };
    }

    /** @return array<string, string> */
    public static function optionsForMode(?string $mode): array
    {
        $mode = SiteMode::tryFrom((string) $mode) ?? SiteMode::Classic;

        return collect(self::forMode($mode))->mapWithKeys(fn (self $f) => [$f->value => $f->getLabel()])->all();
    }
}
