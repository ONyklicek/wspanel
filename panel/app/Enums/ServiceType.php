<?php

namespace App\Enums;

use NyonCode\WireCore\Foundation\Contracts\Enum\HasLabel;

enum ServiceType: string implements HasLabel
{
    case PhpFpm = 'php-fpm';
    case Proxy = 'proxy';
    case Web = 'web';
    case MariaDb = 'mariadb';
    case PostgreSql = 'postgresql';
    case MongoDb = 'mongodb';
    case Valkey = 'valkey';
    case Worker = 'worker';
    case App = 'app';
    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::PhpFpm => 'PHP-FPM',
            self::Proxy => __('Proxy (TLS)'),
            self::Web => 'Web (směrování a FastCGI)',
            self::MariaDb => 'MariaDB',
            self::PostgreSql => 'PostgreSQL',
            self::MongoDb => 'MongoDB',
            self::Valkey => 'Valkey',
            self::Worker => 'Worker',
            self::App => __('Aplikace (worker / Node)'),
            self::System => __('Systém (pošta, DNS…)'),
        };
    }

    /** Whether `systemctl reload` is meaningful (graceful config reload). */
    public function supportsReload(): bool
    {
        // MariaDB's unit has no reload (CanReload=no): restart applies its config.
        return in_array($this, [self::PhpFpm, self::Proxy, self::Web, self::PostgreSql], true);
    }
}
