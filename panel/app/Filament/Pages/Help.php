<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use Livewire\Attributes\Url;

/**
 * The documentation (docs/*.md) inside the panel.
 */
class Help extends Page
{
    protected string $view = 'filament.pages.help';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $navigationLabel = 'Nápověda';

    protected static ?string $title = 'Nápověda';

    protected static ?int $navigationSort = 99;

    /** Pages in reading order: slug => title. */
    public const PAGES = [
        'README' => 'Přehled',
        'instalace' => 'Instalace',
        'weby' => 'Weby',
        'deploy' => 'Deploy',
        'databaze' => 'Databáze',
        'ssh-sftp' => 'SSH a SFTP',
        'posta' => 'Pošta',
        'dns' => 'DNS',
        'vytizeni' => 'Vytížení serveru',
        'bezpecnost' => 'Bezpečnost',
        'reseni-problemu' => 'Řešení problémů',
        'vyvoj' => 'Vývoj a testování',
    ];

    #[Url]
    public string $doc = 'README';

    public function getTitle(): string|Htmlable
    {
        return self::PAGES[$this->doc] ?? 'Nápověda';
    }

    public static function docsPath(): string
    {
        // Installed panel: docs are copied into resources/docs; in the repo they sit next to panel/.
        return is_dir(resource_path('docs')) ? resource_path('docs') : base_path('../docs');
    }

    public function renderedDoc(): Htmlable
    {
        $slug = array_key_exists($this->doc, self::PAGES) ? $this->doc : 'README';
        $file = self::docsPath()."/{$slug}.md";
        if (! is_file($file)) {
            return new HtmlString('<p>Dokumentace není nainstalovaná.</p>');
        }

        $html = Str::markdown((string) file_get_contents($file), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'heading_permalink' => ['symbol' => '#', 'insert' => 'after', 'id_prefix' => '', 'fragment_prefix' => '', 'apply_id_to_heading' => true],
        ], [new HeadingPermalinkExtension]);

        // Links between documents: "deploy.md#x" -> ?doc=deploy#x
        $html = preg_replace_callback('/href="([a-z0-9-]+|README)\.md(#[^"]*)?"/i', function (array $m) {
            return array_key_exists($m[1], self::PAGES)
                ? 'href="'.e(self::getUrl(['doc' => $m[1]])).($m[2] ?? '').'"'
                : $m[0];
        }, $html);

        return new HtmlString($html);
    }
}
