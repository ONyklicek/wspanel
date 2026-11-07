<?php

namespace App\Models;

use App\Models\Concerns\FollowsParentOwner;
use App\Support\AgentMessages;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    use FollowsParentOwner;

    protected static string $ownerRelation = 'site';

    protected $fillable = ['site_id', 'release', 'status', 'commit', 'ref', 'source', 'error', 'started_at', 'finished_at'];

    /** The agent's English text, shown in Czech (stored as it came). */
    protected function error(): Attribute
    {
        return Attribute::get(fn (?string $value) => AgentMessages::cs($value));
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function statusLabel(): string
    {
        return ['running' => 'běží', 'success' => 'nasazeno', 'failed' => 'selhal', 'rolled_back' => 'vráceno zpět'][$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return ['running' => 'warning', 'success' => 'success', 'failed' => 'danger', 'rolled_back' => 'danger'][$this->status] ?? 'gray';
    }

    /** When it started, as people read it (the release name is a timestamp too). */
    public function startedLabel(): string
    {
        return $this->started_at?->timezone(config('app.timezone'))->format('j. n. Y H:i') ?? $this->release;
    }

    /** "1 min 12 s"; null while it runs. */
    public function durationLabel(): ?string
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }
        $s = max(0, (int) $this->started_at->diffInSeconds($this->finished_at));

        return $s >= 60 ? intdiv($s, 60).' min '.($s % 60).' s' : $s.' s';
    }

    /** What was deployed: the tag or branch, and the short commit. */
    public function versionLabel(): string
    {
        $commit = $this->commit ? substr($this->commit, 0, 7) : null;
        $ref = $this->ref && $this->ref !== $this->commit ? $this->ref : null;

        return implode(' · ', array_filter([$ref, $commit])) ?: '—';
    }
}
