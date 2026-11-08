<?php

namespace App\Enums;

use NyonCode\WireCore\Foundation\Contracts\Enum\HasColor;
use NyonCode\WireCore\Foundation\Contracts\Enum\HasLabel;

enum SshAccess: string implements HasColor, HasLabel
{
    case Off = 'off';
    case Sftp = 'sftp';
    case Shell = 'shell';

    public function getLabel(): string
    {
        return match ($this) {
            self::Off => __('Vypnuto'),
            self::Sftp => __('Jen SFTP'),
            self::Shell => 'SSH + SFTP',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Off => __('Žádné přihlášení.'),
            self::Sftp => __('Přenos souborů, bez shellu.'),
            self::Shell => __('Shell s composerem, npm, git, mc a nano. Verze PHP a Node odpovídají webu.'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Off => 'gray',
            self::Sftp => 'info',
            self::Shell => 'warning',
        };
    }
}
