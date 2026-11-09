<?php

namespace App\Livewire\Games;

use App\Actions\Games\ManageGameServers;
use App\Enums\Game;
use App\Livewire\Games\Concerns\IsGameTab;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Resources\GameServerResource;
use Illuminate\Contracts\View\View;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** Who may play and who may not: whitelist, operators, bans (Minecraft). */
class GamePlayers extends ViewPage
{
    use IsGameTab;

    protected static ?string $resource = GameServerResource::class;

    /** @var array<string, mixed>|null */
    public ?array $lists = null;

    /** @var list<string> */
    public array $online = [];

    public bool $running = false;

    public string $name = '';

    public string $reason = '';

    public ?string $error = null;

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
        $this->load();
    }

    protected function tabLabel(): string
    {
        return __('Hráči');
    }

    public function load(): void
    {
        if ($this->server()->game !== Game::Minecraft) {
            return;
        }
        $games = app(ManageGameServers::class);
        try {
            $this->lists = $games->playerLists($this->server());
            $status = $games->status($this->server());
            $this->running = ($status['state'] ?? '') === 'active';
            $this->online = $status['players']['names'] ?? [];
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** whitelist_add, op, ban… for the name typed in (or given). */
    public function act(string $action, ?string $name = null): void
    {
        $allowed = ['whitelist_on', 'whitelist_off', 'whitelist_add', 'whitelist_remove', 'op', 'deop', 'kick', 'ban', 'pardon', 'ban_ip', 'pardon_ip'];
        if (! in_array($action, $allowed, true)) {
            return;
        }
        $name = trim($name ?? $this->name);
        if (! str_starts_with($action, 'whitelist_o')) {
            $ip = in_array($action, ['ban_ip', 'pardon_ip'], true);
            if ($ip ? filter_var($name, FILTER_VALIDATE_IP) === false : ! preg_match('/^[A-Za-z0-9_]{3,16}$/', $name)) {
                $this->addError('name', $ip ? __('Zadejte IP adresu.') : __('Jméno hráče: 3 až 16 písmen, číslic nebo _.'));

                return;
            }
        }
        try {
            $out = app(ManageGameServers::class)->playerAction($this->server(), $action, str_starts_with($action, 'whitelist_o') ? '' : $name, in_array($action, ['ban', 'kick', 'ban_ip'], true) ? trim($this->reason) : '');
            Toast::success(__('Hotovo'), $out !== '' ? $out : null);
            $this->name = $this->reason = '';
            $this->resetErrorBag();
        } catch (AgentException $e) {
            Toast::error(__('Nepodařilo se'), $e->getMessage());
        }
        usleep(300_000); // the server writes its list files
        $this->load();
    }

    public function render(): View
    {
        return $this->tabView('livewire.games.players');
    }
}
