<?php

namespace App\Models;

use App\Enums\DatabaseEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Database extends Model
{
    protected $fillable = ['engine', 'name', 'site_id', 'size', 'backup_frequency', 'backup_keep', 'synced_at'];

    protected function casts(): array
    {
        return [
            'engine' => DatabaseEngine::class,
            'size' => 'integer',
            'backup_keep' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class)->latest('taken_at');
    }

    /** @return array{engine: string, name: string} */
    public function agentParams(): array
    {
        return ['engine' => $this->engine->value, 'name' => $this->name];
    }

    /** Suggests a valid database name from a domain (shop.example.com -> shop_example_com). */
    public static function suggestName(string $domain): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($domain)), '_');
        if (! preg_match('/^[a-z]/', $name)) {
            $name = 'db_'.$name;
        }

        return rtrim(substr($name, 0, 32), '_');
    }
}
