<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    protected $fillable = ['domain', 'issuer', 'not_before', 'not_after', 'status', 'last_error', 'synced_at'];

    protected function casts(): array
    {
        return ['not_before' => 'datetime', 'not_after' => 'datetime', 'synced_at' => 'datetime'];
    }

    public function issuerLabel(): string
    {
        return match (true) {
            $this->issuer === null => '—',
            str_contains($this->issuer, 'letsencrypt') => "Let's Encrypt",
            str_contains($this->issuer, 'zerossl') => 'ZeroSSL',
            $this->issuer === 'local' => 'interní CA (vývoj)',
            default => $this->issuer,
        };
    }
}
