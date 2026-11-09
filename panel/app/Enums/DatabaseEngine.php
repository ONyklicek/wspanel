<?php

namespace App\Enums;

use NyonCode\WireCore\Foundation\Contracts\Enum\HasLabel;

enum DatabaseEngine: string implements HasLabel
{
    case MariaDb = 'mariadb';
    case PostgreSql = 'postgresql';
    case MongoDb = 'mongodb';

    public function getLabel(): string
    {
        return match ($this) {
            self::MariaDb => 'MariaDB',
            self::PostgreSql => 'PostgreSQL',
            self::MongoDb => 'MongoDB',
        };
    }

    /** phpMyAdmin / Adminer can open it (they do not manage MongoDB). */
    public function hasTools(): bool
    {
        return $this !== self::MongoDb;
    }

    /** A site may have its own server of this engine. */
    public function supportsDedicated(): bool
    {
        return $this !== self::MongoDb;
    }
}
