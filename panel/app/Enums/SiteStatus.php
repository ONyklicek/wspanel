<?php

namespace App\Enums;

use NyonCode\WireCore\Foundation\Contracts\Enum\HasColor;
use NyonCode\WireCore\Foundation\Contracts\Enum\HasLabel;

enum SiteStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Zakládá se'),
            self::Active => __('Běží'),
            self::Failed => __('Chyba'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            self::Failed => 'danger',
        };
    }
}
