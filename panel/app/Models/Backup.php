<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    protected $fillable = ['database_id', 'file', 'size', 'taken_at'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'taken_at' => 'datetime'];
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    public function isPreRestoreSnapshot(): bool
    {
        return str_contains($this->file, '-pre-restore');
    }

    /** Absolute path of the dump; the file name is re-validated before use. */
    public function path(): ?string
    {
        if (! preg_match('/^[a-z][a-z0-9_]{1,47}-\d{8}T\d{6}Z(-pre-restore)?\.(sql\.gz|pgdump)$/', $this->file)
            || ! str_starts_with($this->file, $this->database->name.'-')) {
            return null;
        }

        return rtrim(config('agent.backup_dir'), '/').'/'.$this->database->engine->value.'/'.$this->database->name.'/'.$this->file;
    }
}
