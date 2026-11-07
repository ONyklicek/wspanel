<?php

namespace App\Actions\Clients;

use App\Actions\Sites\ControlSite;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\DnsZone;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentException;
use App\Support\CurrentClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Clients and their sign-ins: create, change, suspend, resume, delete.
 */
class ManageClients
{
    public function __construct(private readonly ControlSite $control) {}

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

            return $client;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Client $client, array $data): void
    {
        $client->update($this->clientAttributes($data));
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
    }

    /**
     * Switches every running site of the client off (visitors get 503) and
     * blocks its sign-ins. Remembers which sites were running, so resume()
     * brings back exactly those.
     *
     * @return list<string> sites that could not be switched off
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
        $client->update(['suspended_at' => now(), 'suspended_sites' => $stopped]);

        return $failed;
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
        });
        $client->update(['suspended_at' => null, 'suspended_sites' => null]);

        return $failed;
    }

    /**
     * Deletes the client and its sign-ins. Refused while it still owns
     * anything: removing a client must never silently remove a website.
     */
    public function delete(Client $client): void
    {
        $owned = $client->sites()->count() + $client->databases()->count() + $client->dnsZones()->count();
        if ($owned > 0) {
            throw new RuntimeException('Klient ještě vlastní weby, databáze nebo DNS zóny. Smažte je nebo je převeďte jinému klientovi.');
        }
        $client->delete();
    }

    /**
     * Moves a site (and its databases and DNS zone) to another owner;
     * null = the administrator.
     */
    public function assignSite(Site $site, ?Client $client): void
    {
        DB::transaction(function () use ($site, $client): void {
            $site->update(['client_id' => $client?->id]);
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
