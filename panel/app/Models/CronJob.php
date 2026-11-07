<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CronJob extends Model
{
    protected $fillable = ['site_id', 'agent_id', 'kind', 'args', 'schedule', 'calendar', 'last_run', 'last_result', 'next_run'];

    protected function casts(): array
    {
        return ['args' => 'array', 'agent_id' => 'integer'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** The command as it runs, e.g. "php artisan backup:run". */
    public function commandLine(): string
    {
        return match ($this->kind) {
            'artisan' => 'php artisan '.implode(' ', $this->args),
            'console' => 'php bin/console '.implode(' ', $this->args),
            default => 'php '.implode(' ', $this->args),
        };
    }

    /**
     * Splits a command typed in the form into agent args (no shell: plain words).
     *
     * @return list<string>
     */
    public static function splitArgs(string $command): array
    {
        $command = (string) preg_replace('~^\s*(php\s+)?(artisan|bin/console)\s+~', '', $command);

        return array_values(array_filter(preg_split('/\s+/', trim($command)) ?: [], fn (string $a) => $a !== ''));
    }
}
