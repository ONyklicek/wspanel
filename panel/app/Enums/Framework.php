<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Framework: string implements HasLabel
{
    case Laravel = 'laravel';
    case Symfony = 'symfony';
    case Php = 'php';

    public function getLabel(): string
    {
        return match ($this) {
            self::Laravel => 'Laravel',
            self::Symfony => 'Symfony',
            self::Php => 'Obecné PHP',
        };
    }
}
