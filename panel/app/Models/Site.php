<?php

namespace App\Models;

use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Enums\SiteStatus;
use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    protected $fillable = [
        'domain', 'mode', 'framework', 'php_version',
        'system_user', 'home', 'doc_root', 'status', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'mode' => SiteMode::class,
            'framework' => Framework::class,
            'status' => SiteStatus::class,
        ];
    }
}
