<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\TranslatesPageText;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use Livewire\Attributes\Url;
use NyonCode\WirePanels\Pages\Page;

/**
 * The documentation (docs/*.md) inside the panel.
 */
class HelpPage extends Page
{
    use TranslatesPageText;

    protected static string $view = 'livewire.pages.help';

    protected static ?string $slug = 'help';

    protected static ?string $navigationLabel = 'Dokumentace';

    protected static ?string $navigationIcon = 'outline:book-open';

    protected static ?string $navigationGroup = 'help';

    protected static int $navigationSort = 10;

    /** Pages in reading order: slug => title. */
    public const PAGES = [
        'README' => 'Přehled',
        'instalace' => 'Instalace',
        'klienti' => 'Klienti a balíčky',
        'weby' => 'Weby',
        'deploy' => 'Deploy',
        'github' => 'GitHub',
        'nasazeni-projektu' => 'Nasazení existujícího projektu',
        'databaze' => 'Databáze',
        'ssh-sftp' => 'SSH a SFTP',
        'posta' => 'Pošta',
        'notifikace' => 'Notifikace',
        'api' => 'API a CLI',
        'dns' => 'DNS',
        'vytizeni' => 'Vytížení serveru',
        'bezpecnost' => 'Bezpečnost',
        'reseni-problemu' => 'Řešení problémů',
        'vyvoj' => 'Vývoj a testování',
    ];

    #[Url]
    public string $doc = 'README';

    public function getTitle(): ?string
    {
        return __(self::PAGES[$this->doc] ?? 'Nápověda');
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
            return new HtmlString(__('<p>Dokumentace není nainstalovaná.</p>'));
        }

        $html = Str::markdown((string) file_get_contents($file), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'heading_permalink' => ['symbol' => '#', 'insert' => 'after', 'id_prefix' => '', 'fragment_prefix' => '', 'apply_id_to_heading' => true],
        ], [new HeadingPermalinkExtension]);

        // Links between documents: "deploy.md#x" -> ?doc=deploy#x
        $html = preg_replace_callback('/href="([a-z0-9-]+|README)\.md(#[^"]*)?"/i', function (array $m) {
            return array_key_exists($m[1], self::PAGES)
                ? 'href="'.e(self::url(parameters: ['doc' => $m[1]])).($m[2] ?? '').'"'
                : $m[0];
        }, $html);

        // Pictures next to the documents: "img/x.png" -> the help image route.
        $html = preg_replace_callback('/src="img\/([a-z0-9-]+\.(?:png|jpg|webp))"/i', fn (array $m) => 'src="'.e(route('help.image', ['file' => $m[1]])).'" loading="lazy"', $html);

        return new HtmlString($html);
    }

    /** A picture of the documentation (docs/img), by a plain file name only. */
    public static function imagePath(string $file): ?string
    {
        if (! preg_match('/^[a-z0-9-]+\.(png|jpg|webp)$/i', $file)) {
            return null;
        }
        $path = self::docsPath().'/img/'.$file;

        return is_file($path) ? $path : null;
    }
}
