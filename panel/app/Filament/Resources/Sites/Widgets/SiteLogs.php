<?php

namespace App\Filament\Resources\Sites\Widgets;

use App\Actions\Sites\ControlSite;
use App\Enums\Framework;
use App\Models\Site;
use App\Services\Agent\AgentException;
use Filament\Widgets\Widget;

/** Tail of the site's PHP error log or framework log. */
class SiteLogs extends Widget
{
    protected string $view = 'filament.resources.sites.widgets.site-logs';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public ?Site $record = null;

    public string $source = 'php';

    public int $lines = 200;

    public string $text = '';

    public ?string $file = null;

    public ?string $error = null;

    public bool $loaded = false;

    /** @return array<string, string> */
    public function sources(): array
    {
        $sources = [];
        if ($this->record?->framework !== null && ! $this->record->framework->isNode()) {
            $sources['php'] = 'PHP chyby';
        }
        if (in_array($this->record?->framework, [Framework::Laravel, Framework::Symfony], true)) {
            $sources['framework'] = $this->record->framework === Framework::Laravel ? 'Laravel (laravel.log)' : 'Symfony (prod.log)';
        }
        if ($this->record?->services()->where('type', 'app')->exists()) {
            $sources['app'] = 'Proces aplikace';
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
            $out = app(ControlSite::class)->logs($this->record, $this->source, lines: $this->lines);
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
}
