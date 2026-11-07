<?php

namespace App\Models;

use App\Enums\SshAccess;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A hosting package: the limits its clients get (null = unlimited). */
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'max_sites', 'max_aliases', 'max_databases', 'max_db_size_mb', 'max_mailboxes',
        'max_mail_quota_mb', 'disk_quota_mb', 'max_cron', 'max_workers', 'fpm_children', 'ssh',
        'allowed_modes', 'allowed_php', 'backups_keep', 'offsite',
    ];

    protected function casts(): array
    {
        return [
            'ssh' => SshAccess::class,
            'allowed_modes' => 'array',
            'allowed_php' => 'array',
            'offsite' => 'boolean',
        ];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }
}
