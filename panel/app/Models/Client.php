<?php

namespace App\Models;

use App\Enums\SshAccess;
use App\Enums\UserRole;
use App\Support\Limits;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hosting client: owns sites, databases and DNS zones, signs in with its
 * users, and gets its limits from a package plus its own overrides.
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $fillable = ['name', 'company', 'email', 'phone', 'package_id', 'overrides', 'linux_group', 'suspended_at', 'suspended_sites', 'notes', 'disk_enforced'];

    protected function casts(): array
    {
        return [
            'overrides' => 'array',
            'suspended_at' => 'datetime',
            'suspended_sites' => 'array',
            'disk_enforced' => 'boolean',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class)->where('role', UserRole::Client->value);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class)->withoutGlobalScope('client');
    }

    public function databases(): HasMany
    {
        return $this->hasMany(Database::class)->withoutGlobalScope('client');
    }

    public function dnsZones(): HasMany
    {
        return $this->hasMany(DnsZone::class)->withoutGlobalScope('client');
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * The effective value of one package column: the client's override when
     * it has one (an override of null means unlimited), else the package's.
     * A client without a package is unlimited.
     */
    public function limit(string $column): mixed
    {
        $overrides = $this->overrides ?? [];
        if (array_key_exists($column, $overrides)) {
            return $overrides[$column];
        }

        return $this->package?->getAttribute($column);
    }

    /** Where the effective value comes from: "override", "package" or "none". */
    public function limitSource(string $column): string
    {
        return match (true) {
            array_key_exists($column, $this->overrides ?? []) => 'override',
            $this->package !== null => 'package',
            default => 'none',
        };
    }

    public function sshLimit(): SshAccess
    {
        $value = $this->limit('ssh');

        return $value instanceof SshAccess ? $value : (SshAccess::tryFrom((string) $value) ?? SshAccess::Shell);
    }

    /** @return array<string, array{used: int, limit: ?int}> */
    public function usage(): array
    {
        return app(Limits::class)->usage($this);
    }
}
