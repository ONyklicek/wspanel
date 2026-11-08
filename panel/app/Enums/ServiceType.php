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
    case Valkey = 'valkey';
    case Worker = 'worker';
    case App = 'app';

    public function getLabel(): string
    {
        return match ($this) {
            self::PhpFpm => 'PHP-FPM',
            self::Proxy => __('Proxy (TLS)'),
            self::Web => 'Web (statika + FastCGI)',
            self::MariaDb => 'MariaDB',
            self::PostgreSql => 'PostgreSQL',
            self::Valkey => 'Valkey',
            self::Worker => 'Worker',
            self::App => __('Aplikace (worker / Node)'),
        };
    }

    /** Whether `systemctl reload` is meaningful (graceful config reload). */
    public function supportsReload(): bool
    {
        return in_array($this, [self::PhpFpm, self::Proxy, self::Web, self::MariaDb, self::PostgreSql], true);
    }
}
