<?php

namespace App\Actions\Sites;

use App\Actions\Databases\ManageDatabases;
use App\Actions\Dns\ManageSiteDns;
use App\Enums\SiteStatus;
use App\Exceptions\LimitReachedException;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Wire\Sites\SiteFields;
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
        private readonly ManageDatabases $databases,
    ) {}

    /**
     * What run() needs of the form (RunPanelTask keeps it encrypted).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function queueable(array $data): array
    {
        return array_intersect_key($data, array_flip(['dns', 'dns_zone', 'cloudflare_token', 'mail', 'db_create', 'db_engine', 'db_name']));
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
        // Before the workers: a queue or Horizon reads the database from .env.
        if ($data['db_create'] ?? false) {
            $notes = [...$notes, ...$this->database($site, $data)];
        }
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
     * The database asked for with the site; a failure is a note, not a
     * failed site (it is made later from the site's page).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function database(Site $site, array $data): array
    {
        $engine = (string) ($data['db_engine'] ?? '');
        $name = trim((string) ($data['db_name'] ?? '')) ?: SiteFields::databaseName($site->domain);
        try {
            $db = $this->databases->create($engine, $name, $site)['database'];

            return [__('Databáze :name je založená, údaje jsou v .env webu.', ['name' => $db->name])];
        } catch (LimitReachedException|AgentException $e) {
            return [__('Databázi se nepodařilo založit: :error Založíte ji u webu (Databáze → Nová databáze).', ['error' => $e->getMessage()])];
        }
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
