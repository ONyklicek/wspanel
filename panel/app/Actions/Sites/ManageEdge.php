<?php

namespace App\Actions\Sites;

use App\Actions\Dns\ManageSiteDns;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\DomainOwnership;
use App\Support\Limits;

/**
 * What the edge proxy does for a site: more domains, redirects, password and
 * IP protection, response headers. The panel keeps the settings (never the
 * password nor its hash); the agent applies and validates them.
 */
class ManageEdge
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @param  array<string, mixed>  $data  form data
     * @return array<string, mixed> the settings as applied
     *
     * @throws AgentException
     */
    public function set(Site $site, array $data): array
    {
        $lines = fn ($v) => array_values(array_filter(array_map('trim', is_array($v) ? $v : (preg_split('/[\s,]+/', (string) $v) ?: [])), fn ($x) => $x !== ''));
        $auth = $data['auth_mode'] ?? 'off';
        $payload = array_filter([
            'domain' => $site->domain,
            'aliases' => $lines($data['aliases'] ?? []),
            'redirect_hosts' => $lines($data['redirect_hosts'] ?? []),
            'redirects' => collect($data['redirects'] ?? [])->filter(fn ($r) => filled($r['from'] ?? null))
                ->map(fn ($r) => ['from' => trim((string) $r['from']), 'to' => trim((string) $r['to']), 'code' => (int) ($r['code'] ?? 301)])->values()->all(),
            'auth_path' => match ($auth) {
                'site' => '/', 'path' => (string) ($data['auth_path'] ?? ''), default => ''
            },
            'auth_user' => $auth === 'off' ? '' : (string) ($data['auth_user'] ?? ''),
            'auth_password' => $auth === 'off' ? '' : (string) ($data['auth_password'] ?? ''),
            'allow_ips' => $lines($data['allow_ips'] ?? ''),
            'allow_path' => (string) ($data['allow_path'] ?? '/') ?: '/',
            'hsts' => (bool) ($data['hsts'] ?? false),
            'security_headers' => (bool) ($data['security_headers'] ?? false),
            'headers' => collect($data['headers'] ?? [])->filter(fn ($h) => filled($h['name'] ?? null))
                ->map(fn ($h) => ['name' => trim((string) $h['name']), 'value' => trim((string) ($h['value'] ?? ''))])->values()->all(),
        ], fn ($v) => $v !== '' && $v !== [] && $v !== false);
        $payload['domain'] = $site->domain;
        foreach (array_diff(array_merge($payload['aliases'] ?? [], $payload['redirect_hosts'] ?? []), self::domains($site)) as $host) {
            if (DomainOwnership::takenByAnother($host, $site->client_id)) {
                throw new AgentException('forbidden', __(':host: doména (nebo její část) patří jinému vlastníkovi.', ['host' => $host]), 'panel');
            }
        }
        $added = count($payload['aliases'] ?? []) + count($payload['redirect_hosts'] ?? []) - $site->extraHostCount();
        if ($added > 0) {
            app(Limits::class)->assertCanAdd($site->client, 'aliases', $added);
        }

        $before = self::domains($site);
        $out = $this->agent->call('site.edge', $payload);
        $site->update(['edge_settings' => $out['edge'] ?? null]);
        // New names into the zones the panel manages for them (best effort:
        // the site's DNS tab shows what is still missing).
        $new = array_values(array_diff(array_merge($payload['aliases'] ?? [], $payload['redirect_hosts'] ?? []), $before));
        if ($new !== []) {
            rescue(fn () => app(ManageSiteDns::class)->addHosts($new), report: true);
        }

        return $out['edge'] ?? [];
    }

    /** Form data from what the site has. */
    public static function formData(Site $site): array
    {
        $e = $site->edge_settings ?? [];

        return [
            'aliases' => $e['aliases'] ?? [],
            'redirect_hosts' => $e['redirect_hosts'] ?? [],
            'redirects' => $e['redirects'] ?? [],
            'auth_mode' => ($e['auth_path'] ?? '') === '' ? 'off' : (($e['auth_path'] ?? '') === '/' ? 'site' : 'path'),
            'auth_path' => ($e['auth_path'] ?? '/') === '/' ? '' : $e['auth_path'],
            'auth_user' => $e['auth_user'] ?? '',
            'allow_ips' => implode("\n", $e['allow_ips'] ?? []),
            'allow_path' => $e['allow_path'] ?? '/',
            'hsts' => (bool) ($e['hsts'] ?? false),
            'security_headers' => (bool) ($e['security_headers'] ?? false),
            'headers' => $e['headers'] ?? [],
        ];
    }

    /** All domains the site answers on (main + aliases + redirecting ones). */
    public static function domains(Site $site): array
    {
        $e = $site->edge_settings ?? [];

        return [$site->domain, ...($e['aliases'] ?? []), ...($e['redirect_hosts'] ?? [])];
    }
}
