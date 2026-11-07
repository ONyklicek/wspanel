<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DatabaseEngine: string implements HasLabel
{
    case MariaDb = 'mariadb';
    case PostgreSql = 'postgresql';

    public function getLabel(): string
    {
        return match ($this) {
            self::MariaDb => 'MariaDB',
            self::PostgreSql => 'PostgreSQL',
        };
    }
}
