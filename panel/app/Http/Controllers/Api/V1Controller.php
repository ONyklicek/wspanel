<?php

namespace App\Http\Controllers\Api;

use App\Actions\Sites\ControlSite;
use App\Actions\Sites\ManageDeploys;
use App\Models\Database;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Site;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST API v1 (docs/api.md, docs/openapi.yaml). Runs as the token's user:
 * the owner scopes decide what exists for the caller, so another client's
 * site is a 404 like a missing one.
 */
class V1Controller
{
    public function me(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json(['name' => $u->name, 'email' => $u->email, 'role' => $u->isAdmin() ? 'admin' : 'client', 'client_id' => $u->client_id]);
    }

    public function sites(): JsonResponse
    {
        return response()->json(['data' => Site::query()->orderBy('domain')->get()->map(fn (Site $s) => $this->site($s))->all()]);
    }

    public function showSite(string $domain): JsonResponse
    {
        return response()->json(['data' => $this->site($this->find($domain), true)]);
    }

    public function deploy(Request $request, string $domain, ManageDeploys $deploys): JsonResponse
    {
        $site = $this->find($domain);
        $data = $request->validate(['ref' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', 'not_regex:/\.\./']]);
        try {
            $release = $deploys->start($site, array_filter(['ref' => $data['ref'] ?? null]));
        } catch (AgentException $e) {
            return response()->json(['error' => $e->errorCode === 'conflict' ? 'a deploy is already running' : $e->getMessage()], $e->errorCode === 'conflict' ? 409 : 502);
        }

        return response()->json(['release' => $release, 'status' => 'running'], 202);
    }

    public function deploys(string $domain, ManageDeploys $deploys): JsonResponse
    {
        $site = $this->find($domain);
        try {
            return response()->json(['data' => $deploys->history($site)['deploys'] ?? []]);
        } catch (AgentException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function restart(string $domain, ControlSite $control): JsonResponse
    {
        try {
            $control->restart($this->find($domain), true);
        } catch (AgentException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['restarted' => true]);
    }

    public function databases(): JsonResponse
    {
        return response()->json(['data' => Database::query()->with('site')->orderBy('name')->get()->map(fn (Database $d) => [
            'name' => $d->name, 'engine' => $d->engine->value, 'site' => $d->site?->domain,
        ])->all()]);
    }

    public function mailDomains(): JsonResponse
    {
        return response()->json(['data' => MailDomain::query()->withCount('mailboxes')->orderBy('domain')->get()->map(fn (MailDomain $d) => [
            'domain' => $d->domain, 'mailboxes' => $d->mailboxes_count, 'webmail' => $d->webmailUrl(),
        ])->all()]);
    }

    public function dnsZones(): JsonResponse
    {
        return response()->json(['data' => DnsZone::query()->orderBy('domain')->get()->map(fn (DnsZone $z) => [
            'domain' => $z->domain, 'provider' => $z->provider->value,
        ])->all()]);
    }

    private function find(string $domain): Site
    {
        return Site::query()->where('domain', strtolower($domain))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function site(Site $s, bool $detail = false): array
    {
        $out = [
            'domain' => $s->domain, 'mode' => $s->mode->value, 'framework' => $s->framework->value, 'php_version' => $s->php_version,
            'status' => $s->status->value, 'enabled' => (bool) $s->enabled, 'healthy' => $s->health_ok, 'url' => $s->url(),
        ];
        if ($detail) {
            $out += ['aliases' => $s->edge_settings['aliases'] ?? [], 'databases' => $s->databases()->pluck('name')->all(),
                'valkey' => $s->valkey !== null, 'health_checked_at' => $s->health_checked_at?->toIso8601String()];
        }

        return $out;
    }
}
