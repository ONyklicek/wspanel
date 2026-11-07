<?php

namespace App\Services;

use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * FrankenPHP does not update itself and statically bundles its dependencies
 * (OpenSSL included): the panel compares the installed version with the
 * latest release so security fixes are not missed.
 */
class FrankenPhpRelease
{
    public function __construct(private readonly SystemInfo $system) {}

    public function installed(): ?string
    {
        try {
            $v = $this->system->versions()['frankenphp'] ?? '';
        } catch (AgentException) {
            return null;
        }

        return preg_match('/v(\d+\.\d+\.\d+)/', $v, $m) ? $m[1] : null;
    }

    public function latest(): ?string
    {
        return Cache::remember('frankenphp.latest', now()->addHours(6), function () {
            try {
                $tag = Http::timeout(5)->acceptJson()->get('https://api.github.com/repos/php/frankenphp/releases/latest')->json('tag_name');
            } catch (Throwable) {
                return null;
            }

            return is_string($tag) && preg_match('/^v?(\d+\.\d+\.\d+)$/', $tag, $m) ? $m[1] : null;
        });
    }

    public function updateAvailable(): bool
    {
        $installed = $this->installed();
        $latest = $this->latest();

        return $installed !== null && $latest !== null && version_compare($latest, $installed, '>');
    }
}
