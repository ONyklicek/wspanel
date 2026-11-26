<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Wire\Sites\SiteActions;
use Illuminate\Support\Carbon;

/**
 * A site's changes that restart or reload something (a pool, a unit, the
 * edge), as background tasks (RunPanelTask): each does what the action did
 * and says how it ended as the task's message and body.
 */
class SiteTasks
{
    /**
     * @return array{message: string, body: string|null}
     *
     * @throws AgentException
     */
    public function tls(Site $site, string $mode, ?string $cert = null, ?string $key = null): array
    {
        $out = app(ManageTls::class)->set($site, $mode, $cert, $key);
        $issued = $out['certificate'] ?? null;

        return ['message' => "{$site->domain}: ".__(ManageTls::MODES[$mode]), 'body' => $issued
            ? 'Vydal '.$issued['issuer'].__(', platí do ').Carbon::parse($issued['not_after'])->format('j. n. Y').'.'
            : __('Certifikát se vydá během chvíle.')];
    }

    /**
     * @return array{message: string, body: string|null}
     *
     * @throws AgentException
     */
    public function addWorker(Site $site, string $worker, ?string $app = null): array
    {
        $out = app(ManageWorkers::class)->create($site, $worker, $app);
        $body = ($out['started'] ?? false) ? __('Běží.') : __('Zaregistrován, spustí se po prvním deployi.');
        $body .= match ($worker) {
            'reverb' => __(' Panel nastavil .env: klíče aplikace, publikování přímo na serveru a VITE_REVERB_* pro prohlížeč (po deployi je sestaví npm run build).'),
            'horizon' => __(' Fronty běží ve Valkey webu (QUEUE_CONNECTION=redis).'),
            'pulsework' => __(' Pulse sbírá data přes Valkey webu (PULSE_INGEST_DRIVER=redis).'),
            'ssr' => ' '.__('Port :port, v .env INERTIA_SSR_URL; deploy sestaví i SSR bundle (npm run build:ssr).', ['port' => $out['port'] ?? '?']),
            'celery', 'celerybeat' => ' '.__('Aplikace Celery: :app. Broker (Valkey webu nebo jiný) si nastavte v aplikaci.', ['app' => $out['app'] ?? '']),
            default => '',
        };
        if (($out['hints'] ?? []) !== []) {
            $body .= "\n".__('Aplikace musí převzít port od panelu').': '.implode("\n", array_map(fn (string $h) => SiteActions::ssrHint($h), $out['hints']));
        }

        return ['message' => __('Worker přidán'), 'body' => $body];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{message: string, body: string|null}
     *
     * @throws AgentException
     */
    public function phpSettings(Site $site, array $values): array
    {
        $applied = app(ManagePhpSettings::class)->set($site, $values);

        return ['message' => __('PHP nastavení uloženo'), 'body' => $applied === [] ? __('Všechny hodnoty jsou výchozí.') : count($applied).__(' vlastních hodnot.')];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{message: string, body: string|null}
     *
     * @throws AgentException
     */
    public function valkey(Site $site, array $data): array
    {
        app(ManageValkey::class)->set($site, $data);
        $name = ($data['server'] ?? 'valkey') === 'redis' ? 'Redis' : 'Valkey';

        return ['message' => $data['enabled'] ? __(':name běží', ['name' => $name]) : __('Vypnuto'), 'body' => $data['enabled'] ? __('Připojení je v .env webu.') : null];
    }

    /**
     * @return array{message: string, body: string|null}
     *
     * @throws AgentException
     */
    public function xhprof(Site $site, ?int $ratio, int $hours): array
    {
        app(ManageXhprof::class)->set($site, $ratio, $hours);

        return ['message' => $ratio !== null ? __('Profilování zapnuto') : __('Profilování vypnuto'), 'body' => $ratio !== null
            ? __('Otevřete web, ať vzniknou první měření. Výsledky: tlačítko Otevřít XHGui v Nastavení → Výkon a ladění, na přehledu webu nebo v menu Další.') : null];
    }
}
