<?php

namespace App\Actions\Server;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;
use Illuminate\Support\Carbon;

/**
 * Copies of backups in S3-compatible storage. The agent keeps the
 * credentials (the secret never comes back to the panel) and uploads every
 * new backup of a site or a database.
 */
class ManageOffsite
{
    /** Common providers: endpoint template, region hint, path-style addressing. */
    public const PROVIDERS = [
        'aws' => ['label' => 'Amazon S3', 'endpoint' => 'https://s3.eu-central-1.amazonaws.com', 'region' => 'eu-central-1', 'path_style' => false],
        'r2' => ['label' => 'Cloudflare R2', 'endpoint' => 'https://<account-id>.r2.cloudflarestorage.com', 'region' => 'auto', 'path_style' => true],
        'b2' => ['label' => 'Backblaze B2', 'endpoint' => 'https://s3.eu-central-003.backblazeb2.com', 'region' => 'eu-central-003', 'path_style' => true],
        'wasabi' => ['label' => 'Wasabi', 'endpoint' => 'https://s3.eu-central-1.wasabisys.com', 'region' => 'eu-central-1', 'path_style' => true],
        'other' => ['label' => 'Jiné (MinIO, Hetzner, …)', 'endpoint' => 'https://', 'region' => 'us-east-1', 'path_style' => true],
    ];

    /** The provider an endpoint belongs to (for the form's preset). */
    public static function providerOf(string $endpoint): string
    {
        $host = (string) parse_url($endpoint, PHP_URL_HOST);

        return match (true) {
            str_ends_with($host, '.amazonaws.com') => 'aws',
            str_ends_with($host, '.r2.cloudflarestorage.com') => 'r2',
            str_ends_with($host, '.backblazeb2.com') => 'b2',
            str_ends_with($host, '.wasabisys.com') => 'wasabi',
            default => 'other',
        };
    }

    public function __construct(
        private readonly AgentClient $agent,
        private readonly AgentJobs $jobs,
    ) {}

    /**
     * @return array{configured: bool, has_secret: bool, config: array<string, mixed>}
     *
     * @throws AgentException
     */
    public function status(): array
    {
        $out = $this->agent->call('offsite.get');

        return ['configured' => (bool) ($out['configured'] ?? false), 'has_secret' => (bool) ($out['has_secret'] ?? false), 'config' => (array) ($out['config'] ?? [])];
    }

    /** Whether new backups are copied off-site (false when the agent cannot say). */
    public function enabled(): bool
    {
        try {
            return (bool) ($this->status()['config']['enabled'] ?? false);
        } catch (AgentException) {
            return false;
        }
    }

    public const TYPES = ['s3' => 'S3 (Amazon S3, Cloudflare R2, Backblaze B2, Wasabi, MinIO)', 'ftp' => 'FTP / FTPS', 'smb' => 'SMB (Windows / NAS sdílená složka)'];

    public const FTP_TLS = ['explicit' => 'FTPS – explicitní TLS (port 21)', 'implicit' => 'FTPS – implicitní TLS (port 990)', 'none' => 'Bez šifrování (nedoporučeno)'];

    /**
     * Saves the storage; the agent tests it with an upload first. An empty
     * secret / password keeps the stored one.
     *
     * @param  array<string, mixed>  $data  form data (fields of the chosen type)
     *
     * @throws AgentException
     */
    public function save(array $data): void
    {
        $type = (string) ($data['type'] ?? 's3');
        $params = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'type' => $type,
            'prefix' => trim((string) ($data['prefix'] ?? '')),
            'keep_days' => (int) ($data['keep_days'] ?? 30),
        ];
        if ($type === 's3') {
            $params += [
                'endpoint' => trim((string) ($data['endpoint'] ?? '')),
                'region' => trim((string) ($data['region'] ?? '')),
                'bucket' => trim((string) ($data['bucket'] ?? '')),
                'path_style' => (bool) ($data['path_style'] ?? true),
                'access_key' => trim((string) ($data['access_key'] ?? '')),
                'secret_key' => (string) ($data['secret_key'] ?? ''),
            ];
        } else {
            $params += [
                'host' => trim((string) ($data['host'] ?? '')),
                'port' => (int) ($data['port'] ?? 0),
                'username' => trim((string) ($data['username'] ?? '')),
                'password' => (string) ($data['password'] ?? ''),
            ];
            $params += $type === 'ftp'
                ? ['ftp_tls' => (string) ($data['ftp_tls'] ?? 'explicit'), 'tls_pin' => trim((string) ($data['tls_pin'] ?? ''))]
                : ['share' => trim((string) ($data['share'] ?? '')), 'smb_domain' => trim((string) ($data['smb_domain'] ?? '')), 'smb_encrypt' => (bool) ($data['smb_encrypt'] ?? true)];
        }
        if (trim((string) ($data['signing_key'] ?? '')) !== '') {
            $params['signing_key'] = trim((string) $data['signing_key']);
        }
        $this->agent->call('offsite.set', $params);
    }

    /**
     * The key backups are signed with: keep it to verify them on a new server.
     *
     * @throws AgentException
     */
    public function signingKey(): string
    {
        return (string) ($this->agent->call('offsite.signing_key')['signing_key'] ?? '');
    }

    /**
     * Copies of a site's backups.
     *
     * @return array<string, array{size: int, modified: Carbon}> by file name
     *
     * @throws AgentException
     */
    public function siteCopies(Site $site): array
    {
        $out = $this->agent->call('offsite.list', ['domain' => $site->domain]);
        $copies = [];
        foreach ($out['objects'] ?? [] as $o) {
            $copies[(string) $o['file']] = ['size' => (int) $o['size'], 'modified' => Carbon::parse($o['modified'])->timezone(config('app.timezone'))];
        }

        return $copies;
    }

    /** @throws AgentException */
    public function uploadSiteBackup(Site $site, string $file): bool
    {
        $job = $this->agent->call('offsite.upload', ['domain' => $site->domain, 'file' => $file]);

        return $this->jobs->await($job['job_id'], 60) !== null;
    }

    /** @throws AgentException */
    public function fetchSiteBackup(Site $site, string $file, bool $unverified = false): bool
    {
        $job = $this->agent->call('offsite.fetch', array_filter(['domain' => $site->domain, 'file' => $file, 'unverified' => $unverified]));

        return $this->jobs->await($job['job_id'], 60) !== null;
    }
}
