<?php

namespace App\Actions\Clients;

use App\Actions\Mail\ManageMailHosting;
use App\Actions\Sites\ControlSite;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\DnsZone;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;
use App\Support\CurrentClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Clients and their sign-ins: create, change, suspend, resume, delete.
 */
class ManageClients
{
    public function __construct(
        private readonly ControlSite $control,
        private readonly AgentClient $agent,
        private readonly AgentJobs $jobs,
    ) {}

    /**
     * A client together with its first sign-in.
     *
     * @param  array{name: string, company?: ?string, email?: ?string, phone?: ?string, package_id?: ?int, notes?: ?string, login_email: string, login_password: string}  $data
     */
    public function create(array $data): Client
    {
        return DB::transaction(function () use ($data): Client {
            $client = Client::create($this->clientAttributes($data));
            $this->addUser($client, $data['name'], $data['login_email'], $data['login_password']);
            $this->applyToServer($client);

            return $client;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Client $client, array $data): void
    {
        $client->update($this->clientAttributes($data));
        if ($client->wasChanged('package_id')) {
            $this->applyToServer($client->fresh());
        }
    }

    /**
     * Makes the server match the client: its Linux group exists, the disk
     * limit is the group quota (where the filesystem has quotas), and each
     * site runs with the package's PHP process limit.
     *
     * @throws AgentException
     */
    public function applyToServer(Client $client): void
    {
        if ($client->linux_group === null) {
            $client->update(['linux_group' => 'wsc_'.$client->id]);
        }
        $this->agent->call('client.create', ['group' => $client->linux_group]);
        $quota = $this->agent->call('client.quota', ['group' => $client->linux_group, 'mb' => (int) ($client->limit('disk_quota_mb') ?? 0)]);
        $client->update(['disk_enforced' => (bool) ($quota['enforced'] ?? false)]);
        foreach ($client->sites()->where('status', 'active')->get() as $site) {
            $this->agent->call('site.limits', ['domain' => $site->domain, 'fpm_children' => (int) ($client->limit('fpm_children') ?? 0)]);
        }
    }

    public function addUser(Client $client, string $name, string $email, string $password): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Client,
            'client_id' => $client->id,
        ]);
    }

    /**
     * Per-client limits that differ from the package. A key present with null
     * means "unlimited"; a key absent means "as the package says".
     *
     * @param  array<string, mixed>  $overrides
     */
    public function setOverrides(Client $client, array $overrides): void
    {
        $client->update(['overrides' => $overrides === [] ? null : $overrides]);
        $this->applyToServer($client);
    }

    /**
     * Switches every running site of the client off (visitors get 503) and
     * blocks its sign-ins, its mailboxes' included (mail still arrives).
     * Remembers what was running, so resume() brings back exactly that.
     *
     * @return list<string> sites and mailboxes that could not be switched off
     */
    public function suspend(Client $client): array
    {
        $failed = [];
        $stopped = [];
        CurrentClient::unrestricted(function () use ($client, &$failed, &$stopped): void {
            foreach ($client->sites()->where('enabled', true)->get() as $site) {
                try {
                    $this->control->disable($site);
                    $stopped[] = $site->id;
                } catch (AgentException $e) {
                    $failed[] = $site->domain.': '.$e->getMessage();
                }
            }
        });
        $blocked = [];
        CurrentClient::unrestricted(function () use ($client, &$failed, &$blocked): void {
            foreach ($this->clientMailboxes($client)->where('enabled', true) as $mailbox) {
                try {
                    app(ManageMailHosting::class)->saveMailbox($mailbox->mailDomain, $mailbox->local_part, null, $mailbox->quota_mb, false, $mailbox);
                    $blocked[] = $mailbox->id;
                } catch (AgentException $e) {
                    $failed[] = $mailbox->address().': '.$e->getMessage();
                }
            }
        });
        $client->update(['suspended_at' => now(), 'suspended_sites' => $stopped, 'suspended_mailboxes' => $blocked]);

        return $failed;
    }

    /** @return Collection<int, Mailbox> */
    private function clientMailboxes(Client $client): Collection
    {
        $domains = MailDomain::acrossClients()->where('client_id', $client->id)->pluck('id');

        return Mailbox::withoutGlobalScopes()->with('mailDomain')->whereIn('mail_domain_id', $domains)->get();
    }

    /** @return list<string> sites that could not be switched back on */
    public function resume(Client $client): array
    {
        $failed = [];
        CurrentClient::unrestricted(function () use ($client, &$failed): void {
            foreach (Site::acrossClients()->whereKey($client->suspended_sites ?? [])->where('client_id', $client->id)->get() as $site) {
                try {
                    $this->control->enable($site);
                } catch (AgentException $e) {
                    $failed[] = $site->domain.': '.$e->getMessage();
                }
            }
            foreach ($this->clientMailboxes($client)->whereIn('id', $client->suspended_mailboxes ?? []) as $mailbox) {
                try {
                    app(ManageMailHosting::class)->saveMailbox($mailbox->mailDomain, $mailbox->local_part, null, $mailbox->quota_mb, true, $mailbox);
                } catch (AgentException $e) {
                    $failed[] = $mailbox->address().': '.$e->getMessage();
                }
            }
        });
        $client->update(['suspended_at' => null, 'suspended_sites' => null, 'suspended_mailboxes' => null]);

        return $failed;
    }

    /**
     * Deletes the client and its sign-ins. Refused while it still owns
     * anything: removing a client must never silently remove a website.
     */
    public function delete(Client $client): void
    {
        $owned = $client->sites()->count() + $client->databases()->count() + $client->dnsZones()->count()
            + MailDomain::acrossClients()->where('client_id', $client->id)->count();
        if ($owned > 0) {
            throw new RuntimeException(__('Klient ještě vlastní weby, databáze, DNS zóny nebo poštovní domény. Smažte je nebo je převeďte jinému klientovi.'));
        }
        if ($client->linux_group !== null) {
            $this->agent->call('client.delete', ['group' => $client->linux_group]);
        }
        $client->delete();
    }

    /**
     * Moves a site (and its databases and DNS zone) to another owner;
     * null = the administrator.
     */
    public function assignSite(Site $site, ?Client $client): void
    {
        if ($client !== null) {
            $this->applyToServer($client);
        }
        // Files and processes move to the client's group (its disk quota).
        $job = $this->agent->call('site.set_group', ['domain' => $site->domain, 'group' => $client?->linux_group ?? '']);
        if (isset($job['job_id']) && $this->jobs->await($job['job_id'], 300) === null) {
            throw new AgentException('timeout', __('Přesun souborů webu ještě běží, zkontrolujte ho za chvíli.'), 'site.set_group');
        }
        $this->agent->call('site.limits', ['domain' => $site->domain, 'fpm_children' => (int) ($client?->limit('fpm_children') ?? 0)]);
        $previous = $site->client_id;
        $hosts = array_merge([$site->domain], $site->edge_settings['aliases'] ?? []);
        DB::transaction(function () use ($site, $client, $previous, $hosts): void {
            $site->update(['client_id' => $client?->id]);
            // Its mail goes with it: the domain must not stay with the old owner.
            MailDomain::acrossClients()->whereIn('domain', $hosts)->where('client_id', $previous)->update(['client_id' => $client?->id]);
            $site->databases()->withoutGlobalScope('client')->update(['client_id' => $client?->id]);
            DnsZone::acrossClients()->where('site_id', $site->id)->update(['client_id' => $client?->id]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clientAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip(['name', 'company', 'email', 'phone', 'package_id', 'notes']));
    }
}
