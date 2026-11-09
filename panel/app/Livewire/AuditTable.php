<?php

namespace App\Livewire;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\Lang;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Concerns\WithTable;
use NyonCode\WireTable\Data\CollectionDataSource;
use NyonCode\WireTable\Filters\SelectFilter;
use NyonCode\WireTable\Table;

/** The agent's own audit log: everything privileged the panel asked for. */
class AuditTable extends Component
{
    use WithTable;

    public const AREAS = [
        'site.' => 'Weby', 'deploy.' => 'Deploy', 'db.' => 'Databáze', 'service.' => 'Služby', 'pkg.' => 'Balíčky', 'game.' => 'Herní servery', 'worker.' => 'Workery',
        'fw.' => 'Firewall', 'ssh.' => 'SSH', 'cron.' => 'Úlohy', 'env.' => '.env', 'ssl.' => 'SSL', 'mail.' => 'Pošta', 'dns.' => 'DNS', 'panel.' => 'Panel',
    ];

    /**
     * Operations that only read (the agent's non-mutating ones, less those a
     * person starts or that reveal a secret, plus the panel's periodic syncs).
     * The panel polls them every few seconds: shown, they bury every change.
     */
    public const READ_OPS = [
        'agent.ping', 'site.health', 'site.processes', 'site.logs', 'mail.status', 'mail.log', 'dns.zones', 'dns.records', 'db.list',
        'db.backups', 'db.server_status', 'site.backups', 'offsite.get', 'offsite.list', 'php.status', 'ssl.status', 'ssl.check',
        'ssl.root_ca', 'sys.resources', 'sys.metrics', 'sys.site_disk', 'cron.list', 'deploy.log', 'deploy.history', 'deploy.remote_ref',
        'audit.tail', 'mail.hosting', 'mail.usage', 'xhprof.status', 'site.import_scan', 'sys.updates', 'fail2ban.status', 'valkey.status',
        'service.status', 'fw.list', 'sys.log', 'config.validate', 'sys.services', 'sys.versions', 'job.status',
        'mail.certs', 'site.traffic', 'sys.settings', 'sys.disk_usage', 'sys.disks', 'sys.network', 'sys.processes',
        'pkg.list', 'pkg.search', 'pkg.preview', 'games.catalog', 'game.list', 'game.status', 'game.logs', 'game.backups',
    ];

    /** @param  array<string, mixed>  $params */
    public static function isRead(string $op, array $params = []): bool
    {
        return in_array($op, self::READ_OPS, true) || ($params['action'] ?? null) === 'get';
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        try {
            $entries = app(AgentClient::class)->call('audit.tail', ['limit' => 500]);
        } catch (AgentException) {
            return [];
        }

        return collect($entries)->values()->map(fn (array $e, int $i) => [
            'id' => $i,
            'time' => Carbon::parse($e['ts'])->format('j. n. Y H:i:s'),
            'ago' => Carbon::parse($e['ts'])->diffForHumans(),
            'op' => $e['op'],
            'area' => collect(array_keys(self::AREAS))->first(fn ($p) => str_starts_with($e['op'], $p)) ?? 'other',
            'kind' => self::isRead($e['op'], (array) ($e['params'] ?? [])) ? 'read' : 'change',
            'params' => isset($e['params']) ? mb_strimwidth(json_encode($e['params'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 120, '…') : '',
            'result' => $e['ok'] ? 'OK' : 'chyba',
            'error' => $e['error'] ?? null,
            'duration' => $e['duration_ms'].' ms',
            'peer' => match ($e['peer_uid'] ?? null) {
                0 => 'root (timer)', null, -1 => '—', default => 'panel'
            },
        ])->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->dataSource(new CollectionDataSource($this->rows()))
            ->columns([
                TextColumn::make('time')->label(__('Čas'))->description(fn ($r) => $r->ago),
                TextColumn::make('op')->label(__('Operace'))->fontFamily('mono')->weight('medium')->searchable(),
                TextColumn::make('params')->label(__('Parametry'))->fontFamily('mono')->searchable(),
                BadgeColumn::make('result')->label(__('Výsledek'))->colors(['OK' => 'success', 'chyba' => 'danger'])->tooltip(fn ($r) => $r->error),
                TextColumn::make('duration')->label(__('Trvání')),
                TextColumn::make('peer')->label(__('Kdo'))->textColor('gray'),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('Druh'))->options(['change' => __('Jen změny'), 'read' => __('Jen čtení')])->default('change'),
                SelectFilter::make('area')->label(__('Oblast'))->options(Lang::values(self::AREAS)),
            ])
            ->perPage(50)
            ->poll('30s');
    }

    public function render(): View
    {
        return view('livewire.pages.audit-table');
    }
}
