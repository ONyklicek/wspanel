<?php

namespace App\Livewire\Games;

use App\Actions\Games\ManageGameServers;
use App\Enums\Game;
use App\Livewire\Games\Concerns\IsGameTab;
use App\Services\Agent\AgentException;
use App\Support\MinecraftProperties;
use App\Support\Toast;
use App\Wire\Resources\GameServerResource;
use Illuminate\Contracts\View\View;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** The game's own settings: Minecraft's server.properties as a form. */
class GameServerSettings extends ViewPage
{
    use IsGameTab;

    protected static ?string $resource = GameServerResource::class;

    /** @var array<string, string> key => value as edited */
    public array $values = [];

    /** @var array<string, string> key => value as the file has it */
    public array $original = [];

    /** @var list<string> the panel's keys present in the file */
    public array $panelKeys = [];

    public bool $exists = false;

    public ?string $error = null;

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
        $this->load();
    }

    protected function tabLabel(): string
    {
        return __('Nastavení');
    }

    public function load(): void
    {
        if ($this->server()->game !== Game::Minecraft) {
            return;
        }
        try {
            $out = app(ManageGameServers::class)->properties($this->server());
            $this->exists = (bool) ($out['exists'] ?? false);
            $this->values = $this->original = $this->panelKeys = [];
            foreach ($out['properties'] ?? [] as $p) {
                if (($p['panel'] ?? false) || MinecraftProperties::panelKey($p['key'])) {
                    $this->panelKeys[] = $p['key'];

                    continue;
                }
                $this->values[$p['key']] = $this->original[$p['key']] = (string) $p['value'];
            }
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * The form's sections: known keys of the file by group, then the rest.
     *
     * @return array<string, list<string>>
     */
    public function sections(): array
    {
        $out = [];
        foreach (MinecraftProperties::GROUPS as $group => $label) {
            $keys = array_values(array_filter(array_keys(MinecraftProperties::KNOWN), fn ($k) => MinecraftProperties::KNOWN[$k][0] === $group && array_key_exists($k, $this->values)));
            if ($keys !== []) {
                $out[__($label)] = $keys;
            }
        }
        $other = array_values(array_diff(array_keys($this->values), array_keys(MinecraftProperties::KNOWN)));
        sort($other);
        if ($other !== []) {
            $out[__('Další nastavení')] = $other;
        }

        return $out;
    }

    public function save(bool $restart = false): void
    {
        $this->resetErrorBag();
        $changed = [];
        $errors = [];
        foreach ($this->values as $key => $value) {
            $value = (string) $value;
            if (! array_key_exists($key, $this->original) || $value === $this->original[$key]) {
                continue;
            }
            if (($why = MinecraftProperties::invalid($key, $value)) !== null) {
                $errors['values.'.$key] = $why;

                continue;
            }
            $changed[$key] = $value;
        }
        if ($errors !== []) {
            foreach ($errors as $k => $m) {
                $this->addError($k, $m);
            }

            return;
        }
        if ($changed === [] && ! $restart) {
            Toast::warning(__('Nic se nezměnilo'));

            return;
        }
        try {
            if ($changed !== []) {
                app(ManageGameServers::class)->setProperties($this->server(), $changed, $restart);
            } else {
                app(ManageGameServers::class)->control($this->server(), 'restart');
            }
            Toast::success($restart ? __('Uloženo, server se restartuje') : __('Uloženo'), $restart ? null : __('Změny platí po restartu serveru.'));
        } catch (AgentException $e) {
            Toast::error(__('Nastavení se nepodařilo uložit'), $e->getMessage());
        }
        $this->load();
    }

    public function render(): View
    {
        return $this->tabView('livewire.games.settings');
    }
}
