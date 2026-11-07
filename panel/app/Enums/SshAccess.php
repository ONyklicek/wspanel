<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum SshAccess: string implements HasColor, HasDescription, HasLabel
{
    case Off = 'off';
    case Sftp = 'sftp';
    case Shell = 'shell';

    public function getLabel(): string
    {
        return match ($this) {
            self::Off => 'Vypnuto',
            self::Sftp => 'Jen SFTP',
            self::Shell => 'SSH + SFTP',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Off => 'Žádné přihlášení.',
            self::Sftp => 'Přenos souborů, bez shellu.',
            self::Shell => 'Shell s composerem, npm, git, mc a nano. Verze PHP a Node odpovídají webu.',
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
