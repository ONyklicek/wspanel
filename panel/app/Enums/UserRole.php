<?php

namespace App\Enums;

use NyonCode\WireCore\Foundation\Contracts\Enum\HasColor;
use NyonCode\WireCore\Foundation\Contracts\Enum\HasLabel;

enum UserRole: string implements HasColor, HasLabel
{
    case Admin = 'admin';
    case Client = 'client';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Správce',
            self::Client => 'Klient',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'warning',
            self::Client => 'info',
        };
    }
}
