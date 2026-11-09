<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\ManageEnvironment;
use App\Livewire\Sites\Concerns\IsSiteTab;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Resources\SiteResource;
use Illuminate\Contracts\View\View;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** The site's variables and secrets (a tab of the site), written to its .env. */
class SiteEnvironment extends ViewPage
{
    use IsSiteTab;

    protected static ?string $resource = SiteResource::class;

    /** @var array<string, string> */
    public array $vars = [];

    /** @var array<string, bool> key => set */
    public array $secrets = [];

    /** @var list<string> */
    public array $managed = [];

    public ?string $error = null;

    /** The form: a new or edited entry. */
    public string $key = '';

    public string $value = '';

    public bool $secret = false;

    /** The key being edited (null = a new one). */
    public ?string $editing = null;

    /** The variable/secret modal is open. */
    public bool $formOpen = false;

    /** The Composer modal is open. */
    public bool $composerOpen = false;

    /** @var list<array<string, string>>|null Composer's auth.json (PHP sites) */
    public ?array $composer = null;

    public string $cType = 'http-basic';

    public string $cHost = '';

    public string $cUser = '';

    public string $cSecret = '';

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
        $this->load();
    }

    protected function tabLabel(): string
    {
        return __('Prostředí');
    }

    public function site(): Site
    {
        /** @var Site */
        return $this->nativeRecord();
    }

    public function load(): void
    {
        try {
            $out = app(ManageEnvironment::class)->list($this->site());
            [$this->vars, $this->secrets, $this->managed] = [$out['vars'], $out['secrets'], $out['managed']];
            if ($this->usesComposer()) {
                $this->composer = app(ManageEnvironment::class)->composerAuth($this->site());
            }
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Opens the modal for a new variable (or a new secret). */
    public function add(bool $secret): void
    {
        $this->cancel();
        [$this->secret, $this->formOpen] = [$secret, true];
    }

    public function edit(string $key, bool $secret): void
    {
        [$this->editing, $this->key, $this->secret, $this->formOpen] = [$key, $key, $secret, true];
        $this->value = $secret ? '' : ($this->vars[$key] ?? '');
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        [$this->editing, $this->key, $this->value, $this->secret, $this->formOpen] = [null, '', '', false, false];
        $this->resetErrorBag();
    }

    public function addComposer(): void
    {
        [$this->cType, $this->cHost, $this->cUser, $this->cSecret, $this->composerOpen] = ['http-basic', '', '', '', true];
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->key = trim($this->key);
        $this->validate([
            'key' => ['required', 'regex:'.ManageEnvironment::KEY_RULE, function (string $a, mixed $v, \Closure $fail) {
                if (in_array($v, $this->managed, true)) {
                    $fail(__('Tuto proměnnou spravuje panel (databáze, Valkey, pošta, workery).'));
                }
                if ($this->editing === null && (array_key_exists($v, $this->vars) || array_key_exists($v, $this->secrets))) {
                    $fail(__('Už existuje: upravte ji v seznamu.'));
                }
            }],
            'value' => [$this->secret ? 'required' : 'nullable', 'string', 'max:8192', 'not_regex:/[\r\n\x00]/'],
        ], ['key.regex' => __('Písmena, číslice a _, nezačíná číslicí.'), 'value.not_regex' => __('Jeden řádek.')], ['key' => __('název'), 'value' => __('hodnota')]);
        try {
            $env = app(ManageEnvironment::class);
            $this->secret ? $env->setSecret($this->site(), $this->key, $this->value) : $env->setVariable($this->site(), $this->key, (string) $this->value);
            Toast::success($this->secret ? __('Tajná hodnota :key uložena', ['key' => $this->key]) : __('Proměnná :key uložena', ['key' => $this->key]), __('Aplikace ji uvidí po restartu nebo dalším deployi.'));
            $this->cancel();
        } catch (AgentException $e) {
            Toast::error(__('Uložení selhalo'), $e->getMessage());
        }
        $this->load();
    }

    /** PHP sites install with Composer. */
    public function usesComposer(): bool
    {
        return in_array($this->site()->mode->value, ['classic', 'worker'], true);
    }

    public function saveComposer(): void
    {
        $this->cHost = strtolower(trim($this->cHost));
        $this->validate([
            'cType' => ['required', 'in:'.implode(',', array_keys(ManageEnvironment::COMPOSER_TYPES))],
            'cHost' => ['required', 'max:255', 'regex:/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/'],
            'cUser' => [$this->cType === 'http-basic' ? 'required' : 'nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'cSecret' => ['required', 'string', 'max:4096', 'not_regex:/[\r\n]/'],
        ], ['cHost.regex' => __('Název hostitele, např. repo.packagist.com.')], ['cHost' => __('hostitel'), 'cUser' => __('jméno'), 'cSecret' => __('heslo nebo token')]);
        try {
            app(ManageEnvironment::class)->setComposerAuth($this->site(), $this->cType, $this->cHost, $this->cSecret, $this->cType === 'http-basic' ? $this->cUser : null);
            Toast::success(__('Přístup pro :host uložen', ['host' => $this->cHost]), __('Composer ho použije při dalším deployi i v SSH.'));
            [$this->cHost, $this->cUser, $this->cSecret, $this->composerOpen] = ['', '', '', false];
        } catch (AgentException $e) {
            Toast::error(__('Uložení selhalo'), $e->getMessage());
        }
        $this->load();
    }

    public function removeComposer(string $type, string $host): void
    {
        if (! array_key_exists($type, ManageEnvironment::COMPOSER_TYPES) && $type !== 'gitlab-oauth') {
            return;
        }
        try {
            app(ManageEnvironment::class)->removeComposerAuth($this->site(), $type, $host);
            Toast::success(__('Přístup pro :host smazán', ['host' => $host]));
        } catch (AgentException $e) {
            Toast::error(__('Smazání selhalo'), $e->getMessage());
        }
        $this->load();
    }

    public function remove(string $key): void
    {
        try {
            app(ManageEnvironment::class)->remove($this->site(), $key);
            Toast::success(__(':key smazána', ['key' => $key]));
        } catch (AgentException $e) {
            Toast::error(__('Smazání selhalo'), $e->getMessage());
        }
        $this->load();
    }

    public function infolist(): Infolist
    {
        return Infolist::make()->schema([]);
    }

    public function render(): View
    {
        return view('livewire.sites.environment', [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => $this->subNavigation($this->nativeRecord()),
            'site' => $this->site(),
        ]);
    }
}
