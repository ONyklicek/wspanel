<?php

namespace App\Actions\Sites;

use App\Actions\Databases\ManageDatabases;
use App\Enums\DatabaseEngine;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\AgentTasks;
use App\Wire\Sites\SiteFields;
use Illuminate\Support\Str;

/**
 * One click: a new site with its app. WordPress is unpacked into the site
 * (the agent checks the download's checksum); Laravel and Symfony are an
 * ordinary deploy of their official skeleton, so updates, rollbacks and
 * backups work like for any other site from then on.
 */
class InstallApp
{
    public const APPS = ['wordpress' => 'WordPress', 'laravel' => 'Laravel (nový projekt)', 'symfony' => 'Symfony (nový projekt)'];

    /** The official skeletons (their current default branches). */
    public const SKELETONS = [
        'laravel' => ['repo' => 'https://github.com/laravel/laravel.git', 'branch' => '13.x'],
        'symfony' => ['repo' => 'https://github.com/symfony/skeleton.git', 'branch' => '7.3'],
    ];

    public function __construct(
        private readonly CreateSite $createSite,
        private readonly ManageDatabases $databases,
        private readonly ManageDeploys $deploys,
        private readonly AgentClient $agent,
    ) {}

    /**
     * @param  array{app: string, domain: string, php_version?: ?string, client_id?: ?int, tls?: string}  $data
     * @return array{site: Site, note: string}
     *
     * @throws AgentException
     */
    public function handle(array $data): array
    {
        $app = $data['app'];
        if (! isset(self::APPS[$app])) {
            throw new AgentException('invalid_params', __('Neznámá aplikace.'), 'panel');
        }
        $engines = array_map(fn (DatabaseEngine $e) => $e->value, app(SystemInfo::class)->dbEngines());
        if ($app === 'wordpress' && ! in_array('mariadb', $engines, true)) {
            throw new AgentException('conflict', __('WordPress potřebuje MariaDB (Hosting → Databáze → Servery).'), 'panel');
        }
        $site = $this->createSite->handle([
            'domain' => $data['domain'], 'framework' => $app === 'wordpress' ? 'php' : $app, 'mode' => 'classic',
            'php_version' => ($data['php_version'] ?? null) ?: app(SystemInfo::class)->defaultPhpVersion(),
        ] + array_filter(['client_id' => $data['client_id'] ?? null, 'tls' => $data['tls'] ?? null]));
        if ($site->status === SiteStatus::Failed) {
            throw new AgentException('op_failed', __('Web se nepodařilo založit: ').$site->last_error, 'panel');
        }
        // The chosen engine and name (empty = after the domain); WordPress always
        // has one, in MariaDB; Laravel and Symfony may go without (db_create off).
        $engine = null;
        if ($app === 'wordpress') {
            $engine = 'mariadb';
        } elseif ($data['db_create'] ?? true) {
            $engine = in_array($chosen = (string) ($data['db_engine'] ?? ''), $engines, true) ? $chosen : ($engines[0] ?? null);
        }
        if ($engine !== null) {
            $this->databases->create($engine, trim((string) ($data['db_name'] ?? '')) ?: SiteFields::databaseName($site->domain), $site);
        }

        if ($app === 'wordpress') {
            $job = $this->agent->call('site.app_install', ['domain' => $site->domain, 'app' => 'wordpress']);
            AgentTasks::start($job, 'site.app_install', [
                'title' => __('WordPress na :domain', ['domain' => $site->domain]),
                'done' => __('WordPress na :domain je připravený', ['domain' => $site->domain]),
                'body' => __('Otevřete web a dokončete instalaci WordPressu (název, účet správce).'),
            ], subject: $site);

            return ['site' => $site, 'note' => __('WordPress se rozbaluje na pozadí; až bude hotový, dáme vědět.')];
        }

        $this->appEnv($site, $app);
        $this->deploys->configure($site, self::SKELETONS[$app] + ['migrate' => $app === 'laravel' && $engine !== null, 'assets' => false, 'auto_rollback' => true, 'keep' => 5]);
        $this->deploys->start($site);

        return ['site' => $site, 'note' => __('Běží první deploy oficiální kostry (').self::SKELETONS[$app]['repo'].__('). Pak web nasazujte ze svého repozitáře (Deploy → Nastavení).')];
    }

    /** The secrets a fresh skeleton needs before its first request. */
    private function appEnv(Site $site, string $app): void
    {
        $env = $this->deploys->envGet($site);
        $set = $app === 'laravel'
            ? ['APP_NAME' => '"'.$site->domain.'"', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => $site->url(), 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32))]
            : ['APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'APP_SECRET' => Str::random(32)];
        foreach ($set as $key => $value) {
            if (! preg_match('/^'.$key.'=.+$/m', $env)) {
                $env = (string) preg_replace('/^'.$key.'=.*\R?/m', '', $env);
                $env = rtrim($env, "\n")."\n{$key}={$value}\n";
            }
        }
        $this->deploys->envSet($site, ltrim($env, "\n"));
    }
}
