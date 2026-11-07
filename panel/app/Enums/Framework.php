<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Framework: string implements HasLabel
{
    case Laravel = 'laravel';
    case Symfony = 'symfony';
    case Php = 'php';
    case Nuxt = 'nuxt';
    case Next = 'next';
    case Node = 'node';

    public function getLabel(): string
    {
        return match ($this) {
            self::Laravel => 'Laravel',
            self::Symfony => 'Symfony',
            self::Php => 'Obecné PHP',
            self::Nuxt => 'Nuxt',
            self::Next => 'Next.js',
            self::Node => 'Node (server.js)',
        };
    }

    public function isNode(): bool
    {
        return in_array($this, [self::Nuxt, self::Next, self::Node], true);
    }

    /** @return list<self> */
    public static function forMode(SiteMode $mode): array
    {
        return match ($mode) {
            SiteMode::Classic => [self::Laravel, self::Symfony, self::Php],
            SiteMode::Worker => [self::Laravel, self::Symfony],
            SiteMode::Node => [self::Nuxt, self::Next, self::Node],
        };
    }

    /** @return array<string, string> */
    public static function optionsForMode(?string $mode): array
    {
        $mode = SiteMode::tryFrom((string) $mode) ?? SiteMode::Classic;

        return collect(self::forMode($mode))->mapWithKeys(fn (self $f) => [$f->value => $f->getLabel()])->all();
    }
}
