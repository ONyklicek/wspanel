<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\ControlSite;
use App\Enums\Framework;
use App\Models\Site;
use App\Services\Agent\AgentException;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Tail of a site's PHP error log, framework log or app process output. */
class SiteLogs extends Component
{
    public int $site;

    public string $source = 'php';

    public int $lines = 200;

    public string $text = '';

    public ?string $file = null;

    public ?string $error = null;

    public bool $loaded = false;

    public function getSite(): Site
    {
        return Site::findOrFail($this->site);
    }

    /** @return array<string, string> */
    public function sources(): array
    {
        $site = $this->getSite();
        $sources = [];
        if (! $site->mode->isApp()) {
            $sources['php'] = __('PHP chyby');
        }
        if (in_array($site->framework, [Framework::Laravel, Framework::Symfony], true)) {
            $sources['framework'] = $site->framework === Framework::Laravel ? __('Laravel (laravel.log)') : __('Symfony (prod.log)');
        }
        if ($site->services()->where('type', 'app')->exists()) {
            $sources['app'] = __('Proces aplikace');
        }

        return $sources;
    }

    public function mount(): void
    {
        $this->source = array_key_first($this->sources()) ?? 'php';
    }

    public function load(): void
    {
        if (! array_key_exists($this->source, $this->sources()) || ! in_array($this->lines, [100, 200, 500, 1000], true)) {
            return;
        }
        $this->loaded = true;
        $this->error = null;
        try {
            $out = app(ControlSite::class)->logs($this->getSite(), $this->source, lines: $this->lines);
            $this->text = ($out['missing'] ?? false) ? '' : (string) ($out['text'] ?? '');
            $this->file = $out['file'] ?? $out['unit'] ?? null;
        } catch (AgentException $e) {
            $this->text = '';
            $this->error = $e->getMessage();
        }
    }

    public function updated(): void
    {
        $this->load();
    }

    public function render(): View
    {
        return view('livewire.sites.site-logs');
    }
}
