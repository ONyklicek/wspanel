<?php

namespace App\Actions\Sites;

use App\Actions\SyncCertificatesAndTasks;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/** HTTPS certificate source of a site: public CA, the local CA or an upload. */
class ManageTls
{
    public const MODES = [
        'auto' => "Let's Encrypt (automaticky)",
        'internal' => 'Interní certifikační autorita',
        'custom' => 'Vlastní certifikát',
    ];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @return array<string, mixed> agent output (certificate summary for custom)
     *
     * @throws AgentException
     */
    public function set(Site $site, string $mode, ?string $cert = null, ?string $key = null): array
    {
        $params = ['domain' => $site->domain, 'mode' => $mode];
        if ($mode === 'custom') {
            $params += ['cert' => trim((string) $cert)."\n", 'key' => trim((string) $key)."\n"];
        }
        $out = $this->agent->call('site.tls', $params);
        $site->update(['tls_mode' => $mode]);
        rescue(fn () => app(SyncCertificatesAndTasks::class)->certificates(), report: false);

        return $out;
    }

    /**
     * @return array{pem: string, subject: string, not_after: string, sha256: string}
     *
     * @throws AgentException
     */
    public function rootCa(): array
    {
        return $this->agent->call('ssl.root_ca');
    }
}
