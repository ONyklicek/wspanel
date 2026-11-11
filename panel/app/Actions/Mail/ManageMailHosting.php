<?php

namespace App\Actions\Mail;

use App\Models\AgentTask;
use App\Models\Client;
use App\Models\MailAlias;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Dns\DomainProof;
use App\Support\AgentTasks;
use App\Support\CurrentClient;
use App\Support\DomainOwnership;
use App\Support\Limits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mail hosting: domains, mailboxes, aliases and autoreplies. The agent
 * holds the server's copy (and renders Postfix/Dovecot from it); the panel
 * mirrors it and hashes passwords before they leave (SHA512-CRYPT).
 */
class ManageMailHosting
{
    public function __construct(
        private readonly AgentClient $agent,
    ) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        return Cache::remember('mail.hosting.status', 30, fn () => $this->agent->call('mail.hosting'));
    }

    /**
     * How many messages per hour a mailbox and a site's SMTP login may send.
     *
     * @return array{mailbox_per_hour: int, site_per_hour: int}
     */
    public function limits(): array
    {
        $l = rescue(fn () => $this->status(), [], report: false)['limits'] ?? [];

        return ['mailbox_per_hour' => (int) ($l['mailbox_per_hour'] ?? 200), 'site_per_hour' => (int) ($l['site_per_hour'] ?? 1000)];
    }

    /** @throws AgentException */
    public function setLimits(int $mailboxPerHour, int $sitePerHour): void
    {
        $this->agent->call('mail.limits.set', ['mailbox_per_hour' => $mailboxPerHour, 'site_per_hour' => $sitePerHour]);
        Cache::forget('mail.hosting.status');
    }

    /** @return array{greylist: bool, antivirus: bool} */
    public function filters(): array
    {
        $f = rescue(fn () => $this->status(), [], report: false)['filters'] ?? [];

        return ['greylist' => (bool) ($f['greylist'] ?? false), 'antivirus' => (bool) ($f['antivirus'] ?? false)];
    }

    /**
     * Greylisting and the antivirus. Switching ClamAV on installs and starts
     * it first (minutes), in the background.
     *
     * @throws AgentException
     */
    public function setFilters(bool $greylist, bool $antivirus): AgentTask
    {
        $job = $this->agent->call('mail.filters.set', ['greylist' => $greylist, 'antivirus' => $antivirus]);
        Cache::forget('mail.hosting.status');

        return AgentTasks::start($job, 'mail.filters.set', [
            'title' => $antivirus ? __('Ochrana pošty (instalace ClamAV)') : __('Ochrana pošty'),
            'done' => __('Ochrana pošty uložena'),
        ], self::class.'@filtersSet', key: 'mail-filters');
    }

    public function filtersSet(AgentTask $task): void
    {
        Cache::forget('mail.hosting.status');
    }

    public function installed(): bool
    {
        return (bool) (rescue(fn () => $this->status(), [], report: false)['installed'] ?? false);
    }

    /**
     * Starts installing mail hosting (an agent job, it takes minutes).
     *
     * @return array<string, mixed> the job (job_id)
     *
     * @throws AgentException
     */
    public function install(): array
    {
        $job = $this->agent->call('mail.install');
        Cache::forget('mail.hosting.status');

        return $job;
    }

    /**
     * Takes mail hosting away (Dovecot, Rspamd, webmail; ports 25, 465,
     * 587, 993, 995 and 4190 close). Refused while a mail domain is left;
     * the sites keep sending mail.
     *
     * @throws AgentException
     */
    public function uninstall(): AgentTask
    {
        $domains = MailDomain::acrossClients()->orderBy('domain')->pluck('domain');
        if ($domains->isNotEmpty()) {
            throw new AgentException('conflict', __('Zbývají poštovní domény (:domains): nejdřív je smažte (Pošta → Domény).', ['domains' => $domains->implode(', ')]), 'panel');
        }
        $job = $this->agent->call('mail.uninstall');
        Cache::forget('mail.hosting.status');

        return AgentTasks::start($job, 'mail.uninstall', [
            'title' => __('Odinstalace pošty (schránky)'),
            'done' => __('Pošta (schránky) odinstalována'),
            'body' => __('Weby dál odesílají poštu přes Postfix.'),
        ], self::class.'@filtersSet', key: 'mail-install');
    }

    /** The host name mail clients and MX records use. */
    public static function mailHost(): string
    {
        return (string) (config('panel.mail_host') ?: parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /** @throws AgentException */
    public function addDomain(string $domain, ?Client $owner = null): MailDomain
    {
        $domain = strtolower(trim($domain));
        if (MailDomain::acrossClients()->where('domain', $domain)->exists()) {
            throw new AgentException('conflict', __('Doména už poštu na tomto serveru má.'), 'panel');
        }
        $owner ??= Client::find(CurrentClient::id());
        $this->assertMayReceive($domain, $owner);
        $record = new MailDomain(['domain' => $domain, 'client_id' => $owner?->id, 'webmail' => true, 'autoconfig' => true]);
        foreach (['webmail', 'autoconfig'] as $feature) {
            $record->{$feature} = ! $this->hostsTaken($record, $feature);
        }
        $this->agent->call('mail.domain.set', ['domain' => $domain, 'enabled' => true, 'webmail' => $record->webmail, 'autoconfig' => $record->autoconfig]);
        // What the mailboxes send is DKIM-signed (the key is kept if a site has one).
        app(ManageMail::class)->dkim($domain);
        $record->save();

        return $record;
    }

    /**
     * Switches the domain's webmail (webmail.<domain>) and the addresses mail
     * clients read their settings from (autoconfig./autodiscover.<domain>).
     *
     * @throws AgentException
     */
    public function setFeatures(MailDomain $domain, bool $webmail, bool $autoconfig): void
    {
        $wanted = (clone $domain)->fill(['webmail' => $webmail, 'autoconfig' => $autoconfig]);
        foreach (['webmail' => $webmail, 'autoconfig' => $autoconfig] as $feature => $on) {
            if ($on && ! $domain->{$feature} && $this->hostsTaken($wanted, $feature)) {
                throw new AgentException('conflict', 'Adresa '.MailDomain::SERVICE_PREFIXES[$feature][0].'.'.$domain->domain.__(' už patří webu.'), 'panel');
            }
        }
        $this->agent->call('mail.domain.set', ['domain' => $domain->domain, 'enabled' => true, 'webmail' => $webmail, 'autoconfig' => $autoconfig]);
        $domain->update(['webmail' => $webmail, 'autoconfig' => $autoconfig]);
    }

    /**
     * The domain's sender lists, applied per recipient at delivery: allowed
     * senders never go to Junk (when SPF passes for them), blocked ones are
     * dropped. null = no lists.
     *
     * @param  array{allow: list<string>, block: list<string>}|null  $spam
     *
     * @throws AgentException
     */
    public function setSpam(MailDomain $domain, ?array $spam): void
    {
        $this->agent->call('mail.spam.set', ['domain' => $domain->domain, 'spam' => $spam]);
        $domain->update(['spam' => $spam]);
    }

    /** A site already answers for one of the feature's host names. */
    private function hostsTaken(MailDomain $domain, string $feature): bool
    {
        foreach (MailDomain::SERVICE_PREFIXES[$feature] as $prefix) {
            $host = $prefix.'.'.$domain->domain;
            if (Site::acrossClients()->get(['domain', 'edge_settings'])->contains(fn (Site $s) => $s->domain === $host
                || in_array($host, $s->edge_settings['aliases'] ?? [], true) || in_array($host, $s->edge_settings['redirect_hosts'] ?? [], true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A one-time link that signs the mailbox in to its domain's webmail.
     *
     * @throws AgentException
     */
    public function webmailLogin(Mailbox $mailbox): string
    {
        return (string) $this->agent->call('mail.webmail.login', ['address' => $mailbox->address()])['url'];
    }

    /**
     * An Apple configuration profile (iPhone, iPad, Mac Mail) for the mailbox.
     * No password inside: the device asks for it.
     */
    public static function appleProfile(Mailbox $mailbox): string
    {
        $host = e(self::mailHost());
        $address = e($mailbox->address());
        $domain = e($mailbox->mailDomain->domain);
        $uuid = fn () => strtoupper((string) Str::uuid());
        $reverse = implode('.', array_reverse(explode('.', $mailbox->mailDomain->domain)));

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
            <plist version="1.0">
            <dict>
              <key>PayloadContent</key>
              <array>
                <dict>
                  <key>EmailAccountDescription</key><string>{$address}</string>
                  <key>EmailAccountType</key><string>EmailTypeIMAP</string>
                  <key>EmailAddress</key><string>{$address}</string>
                  <key>IncomingMailServerAuthentication</key><string>EmailAuthPassword</string>
                  <key>IncomingMailServerHostName</key><string>{$host}</string>
                  <key>IncomingMailServerPortNumber</key><integer>993</integer>
                  <key>IncomingMailServerUseSSL</key><true/>
                  <key>IncomingMailServerUsername</key><string>{$address}</string>
                  <key>OutgoingMailServerAuthentication</key><string>EmailAuthPassword</string>
                  <key>OutgoingMailServerHostName</key><string>{$host}</string>
                  <key>OutgoingMailServerPortNumber</key><integer>465</integer>
                  <key>OutgoingMailServerUseSSL</key><true/>
                  <key>OutgoingMailServerUsername</key><string>{$address}</string>
                  <key>OutgoingPasswordSameAsIncomingPassword</key><true/>
                  <key>PayloadIdentifier</key><string>{$reverse}.mail.{$mailbox->local_part}</string>
                  <key>PayloadType</key><string>com.apple.mail.managed</string>
                  <key>PayloadUUID</key><string>{$uuid()}</string>
                  <key>PayloadVersion</key><integer>1</integer>
                </dict>
              </array>
              <key>PayloadDisplayName</key><string>Pošta {$address}</string>
              <key>PayloadIdentifier</key><string>{$reverse}.mail</string>
              <key>PayloadOrganization</key><string>{$domain}</string>
              <key>PayloadType</key><string>Configuration</string>
              <key>PayloadUUID</key><string>{$uuid()}</string>
              <key>PayloadVersion</key><integer>1</integer>
            </dict>
            </plist>
            XML;
    }

    /**
     * Mail for a domain goes to whoever has it here, so a domain is taken only
     * by the owner of what already answers for it on this server: a site (its
     * domain, an alias or a subdomain of it) or a DNS zone. A client needs one
     * of those of their own; nobody may take a domain another owner's site or
     * zone uses (the administrator included: it would read that client's mail).
     *
     * @throws AgentException
     */
    private function assertMayReceive(string $domain, ?Client $owner): void
    {
        $ownerId = $owner?->id;
        if (DomainOwnership::takenByAnother($domain, $ownerId)) {
            throw new AgentException('forbidden', __('Doména (nebo její část) patří webu, DNS zóně či poště jiného vlastníka.'), 'panel');
        }
        if ($ownerId === null) {
            return;
        }
        if (! DomainOwnership::owns($domain, $ownerId)) {
            throw new AgentException('forbidden', __('Poštu lze zřídit jen pro doménu vašeho webu nebo DNS zóny.'), 'panel');
        }
        // A site or a zone here is no proof: anybody may create one for any
        // name. Mail delivered here is, so the public DNS has to say so.
        if (DomainProof::isAnotherUsersMail($domain, $ownerId)) {
            throw new AgentException('forbidden', __('Na této doméně má e-mail jiný uživatel panelu; poštu pro ni může zřídit jen správce.'), 'panel');
        }
        if (! app(DomainProof::class)->proven($domain, $ownerId)) {
            $txt = DomainProof::challenge($domain, $ownerId);
            throw new AgentException('forbidden', __('DNS zatím neukazuje, že doména :domain je vaše. Nasměrujte její MX na :mail nebo její A záznam na tento server, případně přidejte TXT záznam :name s hodnotou :value, a zkuste to znovu.', [
                'domain' => $domain, 'mail' => self::mailHost(), 'name' => $txt['name'], 'value' => $txt['value'],
            ]), 'panel');
        }
    }

    /** @throws AgentException */
    public function removeDomain(MailDomain $domain): void
    {
        if ($domain->mailboxes()->exists()) {
            throw new AgentException('conflict', __('Doména má schránky, nejdřív je smažte.'), 'panel');
        }
        if ($domain->aliases()->exists()) {
            $this->agent->call('mail.alias.set', ['domain' => $domain->domain, 'aliases' => (object) []]);
        }
        $this->agent->call('mail.domain.set', ['domain' => $domain->domain, 'enabled' => false]);
        $domain->delete();
    }

    /**
     * A new mailbox, or new settings / password of one. Counts against the
     * client's package: mailboxes and the mail quota they add up to.
     *
     * @throws AgentException
     */
    public function saveMailbox(MailDomain $domain, string $localPart, ?string $password, int $quotaMb, bool $enabled, ?Mailbox $existing = null): Mailbox
    {
        $localPart = strtolower(trim($localPart));
        $client = $domain->client;
        $limits = app(Limits::class);
        if ($existing === null) {
            $limits->assertCanAdd($client, 'mailboxes');
        }
        $limits->assertCanAdd($client, 'mail_quota', $quotaMb - ($existing->quota_mb ?? 0));
        if ($existing === null && $domain->aliases()->where('local_part', $localPart)->exists()) {
            throw new AgentException('conflict', "{$localPart}@{$domain->domain} je alias.", 'panel');
        }
        $params = ['address' => $localPart.'@'.$domain->domain, 'quota_mb' => $quotaMb, 'enabled' => $enabled];
        if ($password !== null && $password !== '') {
            $params['password_hash'] = self::hash($password);
        }
        $this->agent->call('mail.mailbox.set', $params);

        return $existing
            ? tap($existing)->update(['quota_mb' => $quotaMb, 'enabled' => $enabled])
            : Mailbox::create(['mail_domain_id' => $domain->id, 'local_part' => $localPart, 'quota_mb' => $quotaMb, 'enabled' => $enabled]);
    }

    /** @throws AgentException */
    public function deleteMailbox(Mailbox $mailbox, bool $removeData = true): void
    {
        $this->agent->call('mail.mailbox.delete', ['address' => $mailbox->address(), 'remove_data' => $removeData]);
        $mailbox->delete();
    }

    /**
     * Replaces the domain's aliases.
     *
     * @param  array<string, list<string>>  $aliases  local part ('' = catch-all) => destinations
     *
     * @throws AgentException
     */
    public function setAliases(MailDomain $domain, array $aliases): void
    {
        $this->agent->call('mail.alias.set', ['domain' => $domain->domain, 'aliases' => (object) $aliases]);
        DB::transaction(function () use ($domain, $aliases) {
            $domain->aliases()->delete();
            foreach ($aliases as $local => $to) {
                MailAlias::create(['mail_domain_id' => $domain->id, 'local_part' => (string) $local, 'destinations' => array_values($to)]);
            }
        });
    }

    /**
     * @param  array{enabled: bool, subject?: string, body?: string, days?: int}  $data
     *
     * @throws AgentException
     */
    public function autoreply(Mailbox $mailbox, array $data): void
    {
        $this->agent->call('mail.autoreply', ['address' => $mailbox->address(), 'enabled' => (bool) $data['enabled'],
            'subject' => (string) ($data['subject'] ?? ''), 'body' => (string) ($data['body'] ?? ''), 'days' => (int) ($data['days'] ?? 1)]);
        $mailbox->update(['autoreply' => $data['enabled'] ? ['subject' => $data['subject'] ?? '', 'body' => $data['body'] ?? '', 'days' => (int) ($data['days'] ?? 1)] : null]);
    }

    /**
     * Forwards the mailbox's incoming mail to other addresses, keeping a copy
     * in the mailbox or not. No addresses = forwarding off.
     *
     * @param  list<string>  $to
     *
     * @throws AgentException
     */
    public function forward(Mailbox $mailbox, array $to, bool $keepCopy): void
    {
        $to = array_values(array_unique(array_filter(array_map(fn ($a) => strtolower(trim((string) $a)), $to))));
        $this->agent->call('mail.forward.set', ['address' => $mailbox->address(), 'to' => $to, 'keep_copy' => $keepCopy]);
        $mailbox->update(['forward' => $to === [] ? null : ['to' => $to, 'keep_copy' => $keepCopy]]);
    }

    /** Refreshes the used storage of the domain's mailboxes. */
    public function refreshUsage(MailDomain $domain): void
    {
        $out = rescue(fn () => $this->agent->call('mail.usage', ['domain' => $domain->domain]), [], report: false);
        foreach ($domain->mailboxes as $mb) {
            if (isset($out['usage_kb'][$mb->address()])) {
                $mb->update(['used_kb' => (int) $out['usage_kb'][$mb->address()]]);
            }
        }
    }

    /** SHA512-CRYPT ($6$), as Dovecot verifies it. */
    public static function hash(string $password): string
    {
        $salt = substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16);
        $hash = crypt($password, '$6$rounds=100000$'.$salt.'$');
        if (! str_starts_with($hash, '$6$')) {
            throw new AgentException('op_failed', __('Heslo se nepodařilo zahashovat.'), 'panel');
        }

        return $hash;
    }

    /** A random password for a new mailbox (shown once). */
    public static function randomPassword(): string
    {
        return Str::password(16, symbols: false);
    }

    /**
     * Settings for mail clients.
     *
     * @return array<string, string>
     */
    public static function clientSettings(?MailDomain $domain = null): array
    {
        $host = self::mailHost();
        $webmail = $domain?->webmailUrl() ? ['Webmail' => $domain->webmailUrl()] : [];

        return $webmail + ['IMAP' => "{$host}:993 (SSL/TLS)", 'POP3' => "{$host}:995 (SSL/TLS)", 'SMTP' => "{$host}:465 (SSL/TLS) nebo :587 (STARTTLS)",
            __('Přihlášení') => __('celá e-mailová adresa a heslo schránky (ve webmailu stačí jméno před @)')];
    }
}
