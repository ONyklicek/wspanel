<?php

namespace App\Support;

use App\Actions\Monitoring\ServerMetrics;
use App\Enums\ServiceType;
use App\Enums\SiteMode;
use App\Enums\SshAccess;
use App\Exceptions\LimitReachedException;
use App\Models\Client;
use App\Models\CronJob;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;

/**
 * The one place that knows what a client may still add.
 *
 * Every create path asks here before calling the agent, so a limit cannot be
 * forgotten by one screen. Limits bind a signed-in client; an administrator
 * may knowingly go past them (as in ISPConfig), and admin-owned resources
 * have none.
 */
final class Limits
{
    /**
     * Countable limits: key => [package column, label, unit].
     *
     * @var array<string, array{0: string, 1: string, 2: ?string}>
     */
    public const COUNTED = [
        'sites' => ['max_sites', 'Weby', null],
        'databases' => ['max_databases', 'Databáze', null],
        'aliases' => ['max_aliases', 'Další domény webů', null],
        'cron' => ['max_cron', 'Naplánované úlohy', null],
        'workers' => ['max_workers', 'Workery', null],
        'disk' => ['disk_quota_mb', 'Místo na disku', 'MB'],
        'db_size' => ['max_db_size_mb', 'Velikost databází', 'MB'],
        'mailboxes' => ['max_mailboxes', 'Poštovní schránky', null],
        'mail_quota' => ['max_mail_quota_mb', 'Místo pro poštu', 'MB'],
    ];

    /** The client whose limits apply to the signed-in user, null for an admin. */
    public static function forCurrentUser(): ?Client
    {
        $user = auth()->user();

        return $user instanceof User && ! $user->isAdmin() ? $user->client : null;
    }

    /**
     * Refuses when adding $adding more of $key would exceed the client's limit.
     * A null client (admin-owned) is never limited.
     *
     * @throws LimitReachedException
     */
    public function assertCanAdd(?Client $client, string $key, int $adding = 1): void
    {
        if ($client === null || ! CurrentClient::restricted()) {
            return;
        }
        if ($client->isSuspended()) {
            throw LimitReachedException::make('Účet je pozastavený.');
        }
        [$column, $label] = self::COUNTED[$key];
        $limit = $client->limit($column);
        if ($limit === null) {
            return;
        }
        $used = $this->used($client, $key);
        if ($used + $adding > (int) $limit) {
            throw LimitReachedException::make("Limit balíčku: {$label} {$used} z {$limit}. Pro víc kontaktujte správce.");
        }
    }

    /** Whether one more of $key fits (for hiding a create button). */
    public function canAdd(?Client $client, string $key): bool
    {
        try {
            $this->assertCanAdd($client, $key);

            return true;
        } catch (LimitReachedException) {
            return false;
        }
    }

    /** @throws LimitReachedException */
    public function assertMode(?Client $client, SiteMode|string $mode, ?string $php = null): void
    {
        if ($client === null || ! CurrentClient::restricted()) {
            return;
        }
        $mode = $mode instanceof SiteMode ? $mode->value : $mode;
        $modes = $client->limit('allowed_modes');
        if (is_array($modes) && $modes !== [] && ! in_array($mode, $modes, true)) {
            throw LimitReachedException::make('Tento režim webu balíček nepovoluje.');
        }
        $versions = $client->limit('allowed_php');
        if ($php !== null && is_array($versions) && $versions !== [] && ! in_array($php, $versions, true)) {
            throw LimitReachedException::make("PHP {$php} balíček nepovoluje.");
        }
    }

    /**
     * The site's own Valkey: allowed at all (0 = no) and at most this much memory.
     *
     * @throws LimitReachedException
     */
    public function assertValkey(?Client $client, int $memoryMb): void
    {
        if ($client === null || ! CurrentClient::restricted()) {
            return;
        }
        $limit = $client->limit('max_valkey_mb');
        if ($limit === null) {
            return;
        }
        if ((int) $limit === 0) {
            throw LimitReachedException::make('Valkey balíček nepovoluje.');
        }
        if ($memoryMb > (int) $limit) {
            throw LimitReachedException::make("Balíček povoluje Valkey nejvýš {$limit} MB na web.");
        }
    }

    /** @throws LimitReachedException */
    public function assertSsh(?Client $client, SshAccess $wanted): void
    {
        if ($client === null || ! CurrentClient::restricted() || $wanted === SshAccess::Off) {
            return;
        }
        $allowed = $client->sshLimit();
        $rank = [SshAccess::Off->value => 0, SshAccess::Sftp->value => 1, SshAccess::Shell->value => 2];
        if ($rank[$wanted->value] > $rank[$allowed->value]) {
            throw LimitReachedException::make($allowed === SshAccess::Off ? 'SSH ani SFTP balíček nepovoluje.' : 'Balíček povoluje jen SFTP.');
        }
    }

    /**
     * PHP version options narrowed to what the client's package allows.
     *
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    public static function filterPhp(?Client $client, array $options): array
    {
        $allowed = $client?->limit('allowed_php');

        return is_array($allowed) && $allowed !== [] ? array_intersect_key($options, array_flip($allowed)) : $options;
    }

    /** @return array<string, array{label: string, unit: ?string, used: int, limit: ?int}> */
    public function usage(Client $client): array
    {
        $out = [];
        foreach (self::COUNTED as $key => [$column, $label, $unit]) {
            $limit = $client->limit($column);
            $out[$key] = ['label' => $label, 'unit' => $unit, 'used' => $this->used($client, $key), 'limit' => $limit === null ? null : (int) $limit];
        }

        return $out;
    }

    public function used(Client $client, string $key): int
    {
        $siteIds = fn () => $client->sites()->pluck('id');

        return match ($key) {
            'sites' => $client->sites()->count(),
            'aliases' => (int) $client->sites()->get(['id', 'edge_settings'])->sum(fn (Site $s) => $s->extraHostCount()),
            'databases' => $client->databases()->count(),
            'cron' => CronJob::withoutGlobalScopes()->whereIn('site_id', $siteIds())->count(),
            'workers' => Service::withoutGlobalScopes()->whereIn('site_id', $siteIds())->where('type', ServiceType::Worker)->count(),
            'disk' => (int) ceil($this->diskBytes($client) / 1048576),
            'db_size' => (int) ceil($client->databases()->sum('size') / 1048576),
            'mailboxes' => Mailbox::withoutGlobalScopes()->whereIn('mail_domain_id', MailDomain::acrossClients()->where('client_id', $client->id)->pluck('id'))->count(),
            'mail_quota' => (int) Mailbox::withoutGlobalScopes()->whereIn('mail_domain_id', MailDomain::acrossClients()->where('client_id', $client->id)->pluck('id'))->sum('quota_mb'),
            default => 0,
        };
    }

    /** Bytes the client's sites take, from the agent's (cached) measurement. */
    private function diskBytes(Client $client): int
    {
        $sizes = rescue(fn () => app(ServerMetrics::class)->siteDisk(), [], report: false);

        return (int) $client->sites()->pluck('domain')->sum(fn (string $domain) => $sizes[$domain] ?? 0);
    }
}
