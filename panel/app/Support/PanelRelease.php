<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Which WSPanel this server runs and whether a newer one is out: the newest
 * release of the repository it came from (GitHub's "latest" = the newest
 * one that is not a pre-release), checked daily. The upgrade is the one
 * command the release notes give; the panel never runs it itself.
 */
final class PanelRelease
{
    public const SETTING = 'panel.release';

    private const VERSION_RE = '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/';

    /** This server's version (from the bundle it was installed from); null for a build from source. */
    public static function current(): ?string
    {
        $v = ltrim((string) config('panel.version'), 'v');

        return preg_match(self::VERSION_RE, $v) ? $v : null;
    }

    /** owner/name of the repository the releases come from. */
    public static function repo(): ?string
    {
        $r = (string) config('panel.release_repo');

        return preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $r) ? $r : null;
    }

    /** Whether there is anything to compare: a released version from a known repository. */
    public static function checkable(): bool
    {
        return self::current() !== null && self::repo() !== null;
    }

    /**
     * Asks GitHub for the newest release and keeps the answer.
     *
     * @return array{version: ?string, url: ?string, published_at: ?string, checked_at: string, error: ?string}
     */
    public static function check(): array
    {
        $out = ['version' => null, 'url' => null, 'published_at' => null, 'checked_at' => now()->toIso8601String(), 'error' => null];
        if (! self::checkable()) {
            return $out;
        }
        try {
            $request = Http::timeout(15)->acceptJson()->withHeaders(['User-Agent' => 'WSPanel/'.self::current()]);
            if (filled(config('panel.release_token'))) {
                $request = $request->withToken((string) config('panel.release_token'));
            }
            $response = $request->get('https://api.github.com/repos/'.self::repo().'/releases/latest');
            if ($response->status() === 404) {
                $out['error'] = __('V repozitáři :repo zatím není žádné vydání (nebo je soukromý).', ['repo' => self::repo()]);
            } elseif (! $response->successful()) {
                $out['error'] = 'GitHub: HTTP '.$response->status();
            } else {
                $version = ltrim((string) $response->json('tag_name'), 'v');
                $url = (string) $response->json('html_url');
                if (preg_match(self::VERSION_RE, $version)) {
                    $out['version'] = $version;
                    $out['url'] = str_starts_with($url, 'https://github.com/') ? $url : 'https://github.com/'.self::repo().'/releases';
                    $out['published_at'] = $response->json('published_at');
                } else {
                    $out['error'] = __('Nejnovější vydání nemá číslo verze.');
                }
            }
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        Setting::put(self::SETTING, $out);

        return $out;
    }

    /** @return array{version: ?string, url: ?string, published_at: ?string, checked_at: string, error: ?string}|null */
    public static function last(): ?array
    {
        $v = Setting::get(self::SETTING);

        return is_array($v) ? $v : null;
    }

    /**
     * The newer release, when there is one.
     *
     * @return array{version: string, url: string, published_at: ?Carbon}|null
     */
    public static function available(): ?array
    {
        $last = self::last();
        $current = self::current();
        if ($current === null || ! is_string($last['version'] ?? null) || ! version_compare($last['version'], $current, '>')) {
            return null;
        }

        return [
            'version' => $last['version'],
            'url' => (string) $last['url'],
            'published_at' => isset($last['published_at']) ? Carbon::parse($last['published_at']) : null,
        ];
    }

    /** The command that upgrades this server (the installer keeps its settings). */
    public static function upgradeCommand(): string
    {
        // Installed through apt: the packages and the installer in one step.
        if (config('panel.install_method') === 'apt') {
            return 'sudo wspanel-update';
        }

        return 'curl -fsSL https://github.com/'.(self::repo() ?? '<vlastník>/<repozitář>').'/releases/latest/download/get.sh | sudo bash';
    }
}
