<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SiteMode: string implements HasLabel
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
}
