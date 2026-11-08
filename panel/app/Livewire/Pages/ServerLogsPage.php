<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\TranslatesPageText;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use NyonCode\WirePanels\Pages\Page;

/** The server's own logs (mail, proxy, SSH, fail2ban, DNS, agent, system): one source, a window, a filter. */
class ServerLogsPage extends Page
{
    use TranslatesPageText;

    protected static string $view = 'livewire.pages.server-logs';

    protected static ?string $permission = 'admin';

    protected ?string $title = 'Logy serveru';

    protected static ?string $slug = 'server-logs';

    protected static ?string $navigationLabel = 'Logy';

    protected static ?string $navigationIcon = 'outline:document-text';

    protected static ?string $navigationGroup = 'server';

    protected static int $navigationSort = 15;

    public const SOURCES = [
        'mail' => 'Pošta (Postfix, Dovecot, Rspamd)',
        'proxy' => 'Proxy (HTTPS, certifikáty)',
        'ssh' => 'SSH a SFTP',
        'security' => 'Fail2ban',
        'dns' => 'DNS (PowerDNS)',
        'agent' => 'Agent panelu',
        'system' => 'Systém (varování a chyby)',
    ];

    public string $source = 'mail';

    public string $since = '1h';

    public string $priority = 'all';

    public string $filter = '';

    public bool $follow = false;

    /** @var list<string> */
    public array $lines = [];

    public ?string $error = null;

    public function mount(): void
    {
        $this->load();
    }

    public function load(): void
    {
        $this->source = array_key_exists($this->source, self::SOURCES) ? $this->source : 'mail';
        $this->since = in_array($this->since, ['15m', '1h', '24h', '7d'], true) ? $this->since : '1h';
        $this->priority = in_array($this->priority, ['all', 'warning', 'err'], true) ? $this->priority : 'all';
        $this->filter = mb_substr(str_replace(["\n", "\r", "\0"], ' ', $this->filter), 0, 200);
        try {
            $out = app(AgentClient::class)->call('sys.log', ['source' => $this->source, 'since' => $this->since, 'priority' => $this->priority, 'filter' => trim($this->filter), 'lines' => 1000]);
            $this->lines = array_values($out['lines'] ?? []);
            $this->error = null;
        } catch (AgentException $e) {
            $this->lines = [];
            $this->error = $e->getMessage();
        }
    }

    public function updated(string $name): void
    {
        if ($name !== 'follow') {
            $this->load();
        }
    }
}
