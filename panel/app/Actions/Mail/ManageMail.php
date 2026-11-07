<?php

namespace App\Actions\Mail;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * Outgoing mail: Postfix relay settings, DKIM keys, per-site mode
 * (send through Postfix, or capture in Mailpit) and the mail log.
 */
class ManageMail
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @return array{default_mode: string, relay: string, hostname?: string, queue: int, services?: list<array<string, string>>, sites: array<string, string>, dkim: list<array{domain: string, selector: string, name: string, value: string}>}
     *
     * @throws AgentException
     */
    public function status(): array
    {
        $status = $this->agent->call('mail.status');
        foreach ($status['sites'] ?? [] as $domain => $mode) {
            Site::query()->where('domain', $domain)->where(fn ($q) => $q->whereNull('mail_mode')->orWhere('mail_mode', '!=', $mode))
                ->update(['mail_mode' => $mode]);
        }

        return $status;
    }

    /** @throws AgentException */
    public function setRelay(?string $host, ?int $port = null, ?string $username = null, ?string $password = null): array
    {
        $params = ['host' => (string) $host];
        if ($host) {
            $params += array_filter(['port' => $port, 'username' => $username, 'password' => $password]);
        }

        return $this->agent->call('mail.relay', $params);
    }

    /**
     * @return array{domain: string, selector: string, name: string, value: string}|array{deleted: bool}
     *
     * @throws AgentException
     */
    public function dkim(string $domain, string $action = 'create'): array
    {
        return $this->agent->call('mail.dkim', ['domain' => $domain, 'action' => $action]);
    }

    /** @throws AgentException */
    public function setSiteMode(Site $site, string $mode): void
    {
        $this->agent->call('site.mail', ['domain' => $site->domain, 'mode' => $mode]);
        $site->update(['mail_mode' => $mode]);
    }

    /** @throws AgentException */
    public function log(int $lines = 300): string
    {
        return (string) ($this->agent->call('mail.log', ['lines' => $lines])['text'] ?? '');
    }

    /**
     * DNS records a domain needs so its mail is delivered and not marked as spam.
     *
     * @param  array{name: string, value: string}|null  $dkim
     * @return list<array{type: string, name: string, value: string, note: string}>
     */
    public static function dnsRecords(string $domain, ?array $dkim, ?string $relay, ?string $serverIp): array
    {
        $spf = 'v=spf1 '.($serverIp ? (str_contains($serverIp, ':') ? "ip6:{$serverIp}" : "ip4:{$serverIp}").' ' : 'a mx ')
            .($relay ? 'include:<SPF poskytovatele relaye> ' : '').'~all';
        $records = [
            ['type' => 'TXT', 'name' => $domain, 'value' => $spf, 'note' => 'SPF: kdo smí posílat poštu za doménu.'],
        ];
        if ($dkim !== null) {
            $records[] = ['type' => 'TXT', 'name' => $dkim['name'], 'value' => $dkim['value'], 'note' => 'DKIM: veřejný klíč podpisu.'];
        }
        $records[] = ['type' => 'TXT', 'name' => '_dmarc.'.$domain, 'value' => "v=DMARC1; p=quarantine; rua=mailto:postmaster@{$domain}", 'note' => 'DMARC: co dělat s nepodepsanou poštou.'];

        return $records;
    }
}
