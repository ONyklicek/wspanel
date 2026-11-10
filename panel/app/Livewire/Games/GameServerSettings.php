<?php

namespace App\Livewire\Games;

use App\Actions\Games\ManageGameServers;
use App\Enums\Game;
use App\Livewire\Games\Concerns\IsGameTab;
use App\Services\Agent\AgentException;
use App\Support\FactorioSettings;
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

    /** @var array<string, mixed> Factorio: key => value as edited */
    public array $factorio = [];

    /** @var array<string, mixed> Factorio: as the file has them */
    public array $factorioOriginal = [];

    /** @var array<string, bool> Factorio: which secrets are set */
    public array $secrets = [];

    public string $newToken = '';

    public string $newGamePassword = '';

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
        if ($this->server()->game->is('factorio')) {
            $this->loadFactorio();

            return;
        }
        if (! $this->server()->game->is('minecraft')) {
            return; // an engine with no settings form here
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

    /** A key of the form ("visibility.public" would be a nested path to Livewire). */
    public static function fkey(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private function loadFactorio(): void
    {
        try {
            $out = app(ManageGameServers::class)->factorioSettings($this->server());
            $this->factorio = [];
            foreach (FactorioSettings::FIELDS as $key => $spec) {
                $v = $out['values'][$key] ?? null;
                $this->factorio[self::fkey($key)] = match ($spec[1]) {
                    'bool' => (bool) $v,
                    'int' => (string) (int) $v,
                    'list' => implode(', ', (array) $v),
                    'commands' => $v === 'admins-only' ? 'admins-only' : ($v ? 'true' : 'false'),
                    default => (string) $v,
                };
            }
            $this->factorioOriginal = $this->factorio;
            $this->secrets = $out['secrets'] ?? [];
            $this->exists = true;
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function saveFactorio(bool $restart = false): void
    {
        $this->resetErrorBag();
        $values = [];
        foreach (FactorioSettings::FIELDS as $key => $spec) {
            $v = $this->factorio[self::fkey($key)] ?? null;
            if ($v === ($this->factorioOriginal[self::fkey($key)] ?? null)) {
                continue;
            }
            switch ($spec[1]) {
                case 'int':
                    [$min, $max] = $spec[2];
                    if (! preg_match('/^\d{1,7}$/', (string) $v) || (int) $v < $min || (int) $v > $max) {
                        $this->addError('factorio.'.self::fkey($key), __('Celé číslo :min až :max.', ['min' => $min, 'max' => $max]));

                        continue 2;
                    }
                    $values[$key] = (int) $v;
                    break;
                case 'bool':
                    $values[$key] = (bool) $v;
                    break;
                case 'list':
                    $values[$key] = array_values(array_filter(array_map('trim', explode(',', (string) $v)), fn ($t) => $t !== ''));
                    break;
                case 'commands':
                    $values[$key] = $v === 'admins-only' ? 'admins-only' : $v === 'true';
                    break;
                default:
                    if (strlen((string) $v) > 2000) {
                        $this->addError('factorio.'.self::fkey($key), __('Nejvýš 2000 znaků.'));

                        continue 2;
                    }
                    $values[$key] = (string) $v;
            }
        }
        $secrets = array_filter(['token' => trim($this->newToken), 'game_password' => $this->newGamePassword], fn ($v) => $v !== '');
        foreach ($secrets as $k => $v) {
            if (strlen($v) > 200 || preg_match('/[\r\n]/', $v)) {
                $this->addError($k === 'token' ? 'newToken' : 'newGamePassword', __('Jeden řádek, nejvýš 200 znaků.'));
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        if ($values === [] && $secrets === [] && ! $restart) {
            Toast::warning(__('Nic se nezměnilo'));

            return;
        }
        try {
            app(ManageGameServers::class)->setFactorioSettings($this->server(), $values, $secrets, [], $restart);
            $this->newToken = $this->newGamePassword = '';
            Toast::success($restart ? __('Uloženo, server se restartuje') : __('Uloženo'), $restart ? null : __('Změny platí po restartu serveru.'));
        } catch (AgentException $e) {
            Toast::error(__('Nastavení se nepodařilo uložit'), $e->getMessage());
        }
        $this->loadFactorio();
    }

    public function clearSecret(string $key): void
    {
        if (! in_array($key, ['token', 'game_password'], true)) {
            return;
        }
        try {
            app(ManageGameServers::class)->setFactorioSettings($this->server(), [], [], [$key]);
            Toast::success(__('Odebráno'));
        } catch (AgentException $e) {
            Toast::error(__('Změna selhala'), $e->getMessage());
        }
        $this->loadFactorio();
    }

    public function render(): View
    {
        return $this->tabView('livewire.games.settings');
    }
}
