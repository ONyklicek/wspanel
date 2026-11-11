<?php

namespace App\Services\Dns;

use App\Actions\Mail\ManageMailHosting;
use App\Models\User;
use App\Support\PublicAddress;

/**
 * Whether a client controls a domain in the public DNS, before this server
 * accepts its mail. Mail for a domain set up here is delivered here, also
 * the mail this server sends to it (a password reset of the panel's
 * administrator included): a client must not get it for a domain that is
 * not theirs, such as a large mail provider's or the administrator's own.
 *
 * Proof is anything only the domain's owner can set: its MX or its address
 * pointing to this server, its delegation to this server's nameservers, or
 * a TXT record with a value derived from the client and the domain.
 */
class DomainProof
{
    public function __construct(private readonly Resolver $dns) {}

    /**
     * The TXT record that proves the domain for the owner.
     *
     * @return array{name: string, value: string}
     */
    public static function challenge(string $domain, int $ownerId): array
    {
        $domain = strtolower($domain);
        $token = substr(hash_hmac('sha256', "mail-domain|{$ownerId}|{$domain}", (string) config('app.key')), 0, 32);

        return ['name' => '_wspanel.'.$domain, 'value' => 'wspanel-verify='.$token];
    }

    /**
     * The public DNS points the domain here or carries the owner's TXT record.
     * A name nobody can register (.test, .internal, …) has no public owner
     * to take it from, and nothing to prove it with.
     */
    public function proven(string $domain, int $ownerId): bool
    {
        $domain = strtolower($domain);
        if (! PublicAddress::host($domain)) {
            return true;
        }
        $challenge = self::challenge($domain, $ownerId);
        if (in_array($challenge['value'], $this->dns->records($challenge['name'], DNS_TXT, 'txt'), true)) {
            return true;
        }
        $mailHost = strtolower(ManageMailHosting::mailHost());
        $ips = array_values(array_filter([(string) config('panel.public_ipv4'), (string) config('panel.public_ipv6')]));
        foreach ($this->dns->records($domain, DNS_MX, 'target') as $mx) {
            $mx = rtrim(strtolower($mx), '.');
            if ($mx === $mailHost || array_intersect($this->addresses($mx), $ips) !== []) {
                return true;
            }
        }
        if (array_intersect($this->addresses($domain), $ips) !== []) {
            return true;
        }
        $nameservers = array_map(fn (string $ns) => rtrim(strtolower($ns), '.'), $this->dns->records($domain, DNS_NS, 'target'));

        return array_intersect($nameservers, DnsAdvisor::defaultNameservers()) !== [];
    }

    /**
     * Another person signs in to the panel with an address at the domain (or
     * under it, or above it): their mail, a password reset included, must
     * never reach this owner.
     */
    public static function isAnotherUsersMail(string $domain, int $ownerId): bool
    {
        $domain = strtolower($domain);

        return User::query()
            ->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', '!=', $ownerId))
            ->pluck('email')
            ->map(fn (string $email) => strtolower(substr(strrchr($email, '@') ?: '', 1)))
            ->contains(fn (string $at) => $at !== '' && ($at === $domain || str_ends_with($at, '.'.$domain) || str_ends_with($domain, '.'.$at)));
    }

    /** @return list<string> */
    private function addresses(string $host): array
    {
        return [...$this->dns->records($host, DNS_A, 'ip'), ...$this->dns->records($host, DNS_AAAA, 'ipv6')];
    }
}
