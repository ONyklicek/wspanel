<?php

namespace App\Console\Commands;

use App\Actions\Server\ManageMirror;
use App\Enums\DnsProvider;
use App\Models\DnsZone;
use App\Services\Agent\AgentException;
use App\Services\Dns\CloudflareDns;
use App\Services\Dns\DnsException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * The mirror's public DNS: on the active node (the primary), the A records
 * in Cloudflare that still point at the other node's public address point
 * here. Runs every minute; nothing to do while both nodes share one public
 * address (then the router forwards, see the promote hooks) or on a standby.
 * The LAN's Windows DNS is the agent's (it works without the panel).
 */
#[Signature('panel:mirror-dns')]
#[Description('Point the Cloudflare records of the sites at the mirror\'s active node')]
class MirrorDns extends Command
{
    public function handle(ManageMirror $mirror): int
    {
        try {
            $st = $mirror->status();
        } catch (AgentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $own = (string) ($st['public_ipv4'] ?? '');
        $other = (string) ($st['peer']['public_ipv4'] ?? '');
        if (($st['role'] ?? '') !== 'primary' || $own === '' || $other === '' || $own === $other) {
            return self::SUCCESS;
        }
        $changed = [];
        foreach (DnsZone::acrossClients()->where('provider', DnsProvider::Cloudflare)->get() as $zone) {
            try {
                $api = $zone->api();
                if ($api instanceof CloudflareDns) {
                    $changed = [...$changed, ...$api->repointA($other, $own)];
                }
            } catch (DnsException|ConnectionException $e) {
                $this->warn($zone->domain.': '.$e->getMessage());
            }
        }
        if ($changed !== []) {
            Log::info('mirror: Cloudflare records point at this node', ['names' => $changed, 'from' => $other, 'to' => $own]);
            $this->info('Cloudflare → '.$own.': '.implode(', ', $changed));
        }

        return self::SUCCESS;
    }
}
