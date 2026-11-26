<?php

namespace App\Actions\Sites;

use App\Actions\Dns\ManageSiteDns;
use App\Enums\SiteStatus;
use App\Exceptions\LimitReachedException;
use App\Models\Site;
use App\Services\Agent\AgentException;
use RuntimeException;
use Throwable;

/**
 * A new site's slow part, in the background (the queue worker, RunPanelTask):
 * the agent sets it up, then the workers and the DNS (and mail) chosen with
 * it. The form only checked and stored the site; its page shows this running.
 *
 * A failed provisioning fails the task and leaves the site with its error
 * (it can be deleted and created again). A worker or the DNS that fails is
 * said in the result: the site is there and they can be added from it.
 */
class SetUpNewSite
{
    public function __construct(
        private readonly CreateSite $createSite,
        private readonly ManageWorkers $workers,
        private readonly ManageSiteDns $dns,
    ) {}

    /**
     * What run() needs of the form (RunPanelTask keeps it encrypted).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function queueable(array $data): array
    {
        return array_intersect_key($data, array_flip(['dns', 'dns_zone', 'cloudflare_token', 'mail']));
    }

    /**
     * @param  list<string>  $workers
     * @param  array<string, mixed>  $data  the form (dns, mail)
     * @return array{message: string, body: string|null}
     *
     * @throws RuntimeException when the site could not be set up
     */
    public function run(Site $site, array $workers, array $data): array
    {
        $this->createSite->provision($site);
        if ($site->refresh()->status === SiteStatus::Failed) {
            throw new RuntimeException(__('Web se nepodařilo založit: ').$site->last_error);
        }
        $notes = [];
        $failed = [];
        foreach ($workers as $worker) {
            try {
                $this->workers->create($site, $worker);
            } catch (LimitReachedException|AgentException $e) {
                $failed[] = $worker.': '.$e->getMessage();
            }
        }
        if ($failed !== []) {
            $notes[] = __('Některé workery se nepřidaly').': '.implode(' · ', $failed);
        }
        $notes = [...$notes, ...$this->dns($site, $data)];

        return ['message' => __('Web :domain založen', ['domain' => $site->domain]), 'body' => $notes === [] ? null : implode(' ', $notes)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function dns(Site $site, array $data): array
    {
        if (($data['dns'] ?? 'none') === 'none' && ! ($data['mail'] ?? false)) {
            return [];
        }
        $notes = [];
        try {
            $out = $this->dns->setUpForNewSite($site, $data);
            if ($out['zone'] !== null) {
                $notes[] = __('DNS v zóně :zone', ['zone' => $out['zone']]).': '.($out['added'] === []
                    ? __('Nic nechybělo.')
                    : __('Přidáno: :list', ['list' => implode(', ', $out['added'])]).($out['kept'] === [] ? '' : ' '.__('Ponecháno (míří jinam): :list', ['list' => implode(', ', $out['kept'])])));
            }
            if ($out['mail_error'] !== null) {
                $notes[] = __('Web je založený, pošta zatím ne').': '.$out['mail_error'].' '.__('Poštu pak přidáte v záložce Pošta webu.');
            }
        } catch (Throwable $e) {
            report($e);
            $notes[] = __('Web je založený, DNS se nepodařilo nastavit').': '.$e->getMessage().' '.__('Dokončíte to v záložce DNS webu.');
        }

        return $notes;
    }
}
