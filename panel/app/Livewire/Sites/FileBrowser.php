<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\ManageFiles;
use App\Forms\Components\CodeIde;
use App\Models\GameServer;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Actions\Action;
use App\Wire\Actions\HeaderAction;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Livewire\Attributes\Url;
use Livewire\Component;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireCore\Actions\BulkAction;
use NyonCode\WireForms\Components\FileUpload;
use NyonCode\WireForms\Components\Hidden;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Concerns\WithTable;
use NyonCode\WireTable\Data\CollectionDataSource;
use NyonCode\WireTable\Table;

/**
 * The site's files, as its own user sees them, inside its home only.
 */
class FileBrowser extends Component
{
    use WithTable;

    /** The site whose files these are, or (with $game) none. */
    public ?int $site = null;

    /** A game server's files instead (its home, as its own user). */
    public ?int $game = null;

    /** Folder shown, relative to the site's home ("." = the home). */
    #[Url(as: 'dir')]
    public string $path = '.';

    public ?string $error = null;

    /** @var list<string> problems of the files in the current upload */
    public array $uploadErrors = [];

    public function getSite(): Site|GameServer
    {
        return $this->game !== null ? GameServer::findOrFail($this->game) : Site::findOrFail($this->site);
    }

    private function files(): ManageFiles
    {
        return app(ManageFiles::class);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        try {
            $this->error = null;
            $entries = $this->files()->list($this->getSite(), $this->path);
        } catch (AgentException $e) {
            $this->error = $e->getMessage();

            return [];
        }

        return collect($entries)->map(fn (array $e) => $e + [
            'id' => $e['name'],
            'size_label' => $e['type'] === 'dir' ? '—' : Number::fileSize((int) $e['size'], 1),
            'mtime_label' => $e['mtime'] ? Carbon::createFromTimestamp($e['mtime'])->timezone(config('app.timezone'))->format('j. n. Y H:i') : '',
        ])->values()->all();
    }

    /** @return list<array{name: string, path: string}> */
    public function breadcrumbs(): array
    {
        $target = $this->getSite();
        $crumbs = [['name' => $target instanceof GameServer ? $target->name : $target->domain, 'path' => '.']];
        $acc = '';
        foreach (array_filter(explode('/', trim($this->path, './'))) as $part) {
            $acc = ManageFiles::join($acc, $part);
            $crumbs[] = ['name' => $part, 'path' => $acc];
        }

        return $crumbs;
    }

    public function go(string $path): void
    {
        $this->path = trim($path, '/') === '' ? '.' : trim($path, '/');
        $this->resetTablePage();
    }

    public function up(): void
    {
        $parent = dirname($this->path);
        $this->go($parent === '.' || $parent === '/' ? '.' : $parent);
    }

    private function full(string $name): string
    {
        return ManageFiles::join($this->path, $name);
    }

    private function attempt(string $failure, callable $fn): void
    {
        try {
            $fn();
        } catch (AgentException|\RuntimeException $e) {
            Toast::error($failure, $e->getMessage());
        }
        $this->resetTable();
    }

    private function resetTable(): void
    {
        if (method_exists($this, 'invalidateTable')) {
            $this->invalidateTable();
        }
    }

    private function resetTablePage(): void
    {
        $this->setPage(1);
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $nameRule = function (string $attribute, mixed $value, \Closure $fail) {
            if (! ManageFiles::validName((string) $value)) {
                $fail(__('Jen název, bez lomítek.'));
            }
        };

        return $table
            ->stackedOnMobile()
            ->dataSource(new CollectionDataSource($this->rows()))
            ->columns([
                TextColumn::make('name')->label(__('Název'))->searchable()->weight('medium')
                    ->icon(fn ($record) => match ($record->type) {
                        'dir' => 'outline:folder', 'link' => 'outline:link', default => 'outline:document'
                    })
                    ->iconColor(fn ($record) => $record->type === 'dir' ? 'warning' : 'gray')
                    ->description(fn ($record) => $record->type === 'link' ? '→ '.$record->target : null),
                TextColumn::make('size_label')->label(__('Velikost')),
                TextColumn::make('mtime_label')->label(__('Změněno')),
                TextColumn::make('mode')->label(__('Práva'))->fontFamily('mono')->textColor('gray'),
            ])
            ->recordAction('open')
            ->actions([
                Action::make('open')->label(__('Otevřít'))->icon('outline:folder-open')->color('gray')->onlyIcon()->tooltip(__('Otevřít'))
                    ->visible(fn ($record) => in_array($record->type, ['dir', 'link'], true))
                    ->action(fn ($record) => $this->go($this->full($record->name))),
                $this->editAction(),
                Action::make('download')->label(__('Stáhnout'))->icon('outline:arrow-down-tray')->color('gray')->onlyIcon()->tooltip(__('Stáhnout'))
                    ->visible(fn ($record) => $record->type === 'file')
                    ->url(fn ($record) => $this->game !== null
                        ? route('game-files.download', [$this->game, 'path' => $this->full($record->name)])
                        : route('site-files.download', [$this->site, 'path' => $this->full($record->name)]), true),
                ActionGroup::make([
                    Action::make('rename')->label(__('Přejmenovat / přesunout'))->icon('outline:pencil')
                        ->fillFormUsing(fn ($record) => ['to' => $this->full($record->name)])
                        ->form([TextInput::make('to')->label(__('Nová cesta'))->required()->maxLength(4096)
                            ->helperText(__('Relativně k domovu webu, např. current/public/obrazky/logo.png'))])
                        ->action(fn ($record, array $data) => $this->attempt(__('Přejmenování selhalo'), function () use ($record, $data) {
                            $this->files()->rename($this->getSite(), $this->full($record->name), $data['to']);
                            Toast::success(__('Přejmenováno'));
                        })),
                    Action::make('chmod')->label(__('Oprávnění'))->icon('outline:lock-closed')
                        ->fillFormUsing(fn ($record) => ['mode' => ltrim((string) $record->mode, '0') ?: '0'])
                        ->form([TextInput::make('mode')->label(__('Práva (osmičkově)'))->required()->rules(['regex:/^[0-7]{3}$/'])
                            ->helperText(__('Např. 644 pro soubory, 755 pro složky a spustitelné skripty.'))])
                        ->action(fn ($record, array $data) => $this->attempt(__('Změna práv selhala'), function () use ($record, $data) {
                            $this->files()->chmod($this->getSite(), $this->full($record->name), $data['mode']);
                            Toast::success(__('Oprávnění změněna'));
                        })),
                    Action::make('extract')->label(__('Rozbalit sem'))->icon('outline:archive-box-arrow-down')
                        ->visible(fn ($record) => $record->type === 'file' && str_ends_with(strtolower($record->name), '.zip'))
                        ->fillFormUsing(fn ($record) => ['to' => $this->full(pathinfo($record->name, PATHINFO_FILENAME))])
                        ->form([TextInput::make('to')->label(__('Do složky'))->required()])
                        ->action(fn ($record, array $data) => $this->attempt(__('Rozbalení selhalo'), function () use ($record, $data) {
                            $n = $this->files()->extract($this->getSite(), $this->full($record->name), $data['to']);
                            Toast::success(__('Rozbaleno :n souborů', ['n' => $n]));
                        })),
                    Action::make('delete')->label(__('Smazat'))->icon('outline:trash')->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(fn ($record) => 'Smazat '.$record->name.'?')
                        ->modalDescription(fn ($record) => $record->type === 'dir' ? __('Smaže složku i celý její obsah. Nejde to vrátit.') : __('Nejde to vrátit.'))
                        ->action(fn ($record) => $this->attempt(__('Smazání selhalo'), function () use ($record) {
                            $this->files()->delete($this->getSite(), $this->full($record->name));
                            Toast::success(__('Smazáno'));
                        })),
                ]),
            ])
            ->bulkActions([
                BulkAction::make('compress')->label(__('Zabalit do zip'))->icon('outline:archive-box')
                    ->form([TextInput::make('to')->label(__('Název archivu'))->default('archiv.zip')->required()->rules(['regex:/\.zip$/i', $nameRule])])
                    ->action(fn ($records, array $data) => $this->attempt(__('Zabalení selhalo'), function () use ($records, $data) {
                        $n = $this->files()->compress($this->getSite(), $records->map(fn ($r) => $this->full($r->name))->values()->all(), $this->full($data['to']));
                        Toast::success(__('Zabaleno :n souborů do :to', ['n' => $n, 'to' => $data['to']]));
                    })),
            ])
            ->headerActions(array_values(array_filter([
                $this->envPath() ? $this->editEnv() : null,
                HeaderAction::make('upload')->label(__('Nahrát'))->icon('outline:arrow-up-tray')
                    ->modalDescription(__('Nahraje soubory do aktuální složky (každý nejvýš 512 MB; větší přes SFTP).'))
                    ->form([
                        Toggle::make('replace')->label(__('Přepsat existující soubory se stejným názvem'))->live(),
                        // Each file goes straight to the agent when the form is submitted.
                        FileUpload::make('files')->label(__('Soubory'))->maxFiles(50)->maxSize(524288)->required()
                            ->storeFileUsing(function ($file, $get) {
                                $name = (string) $file->getClientOriginalName();
                                if (! ManageFiles::validName($name)) {
                                    $this->uploadErrors[] = __(':name: neplatný název', ['name' => $name]);

                                    return '';
                                }
                                try {
                                    $this->files()->upload($this->getSite(), $file->getRealPath(), $this->full($name), (bool) $get('replace'));
                                } catch (AgentException|\RuntimeException $e) {
                                    $this->uploadErrors[] = "{$name}: {$e->getMessage()}";

                                    return '';
                                }

                                return $this->full($name);
                            }),
                    ])
                    ->action(function (array $data) {
                        $done = count(array_filter((array) ($data['files'] ?? [])));
                        $this->uploadErrors === []
                            ? Toast::success(__('Nahráno :done souborů', ['done' => $done]))
                            : Toast::error(__('Nahráno :done, některé soubory selhaly', ['done' => $done]), implode("\n", $this->uploadErrors));
                        $this->uploadErrors = [];
                        $this->resetTable();
                    }),
                HeaderAction::make('newFile')->label(__('Nový soubor'))->icon('outline:document-plus')->color('gray')
                    ->form([TextInput::make('name')->label(__('Název'))->required()->rules([$nameRule])])
                    ->action(fn (array $data) => $this->attempt(__('Soubor se nepodařilo vytvořit'), function () use ($data) {
                        $this->files()->write($this->getSite(), $this->full($data['name']), '');
                        Toast::success(__('Soubor vytvořen'));
                    })),
                HeaderAction::make('newFolder')->label(__('Nová složka'))->icon('outline:folder-plus')->color('gray')
                    ->form([TextInput::make('name')->label(__('Název'))->required()->rules([$nameRule])])
                    ->action(fn (array $data) => $this->attempt(__('Složku se nepodařilo vytvořit'), function () use ($data) {
                        $this->files()->mkdir($this->getSite(), $this->full($data['name']));
                        Toast::success(__('Složka vytvořena'));
                    })),
            ])))
            ->emptyState(
                $this->error ? __('Složku se nepodařilo načíst') : __('Prázdná složka'),
                $this->error ?? __('Nahrajte soubory nebo vytvořte nový.'),
                'outline:folder-open',
            )
            ->paginated(false);
    }

    /** The editor, for the clicked row (path null) or a fixed file. */
    private function editAction(?string $fixed = null): Action|HeaderAction
    {
        $path = fn ($record = null) => $fixed ?? $this->full($record->name);
        $action = $fixed === null
            ? Action::make('edit')->label(__('Upravit'))->icon('outline:pencil-square')->color('gray')->onlyIcon()->tooltip(__('Upravit'))
                ->visible(fn ($record) => $record->type === 'file' && (int) $record->size <= ManageFiles::MAX_EDIT)
            : HeaderAction::make('editEnv')->label(__('Upravit ').basename($fixed))->icon('outline:key')->color('gray');

        return $action
            ->modalHeading(fn ($record = null) => $path($record))
            ->modalDescription($fixed === null ? null : __('Proměnné prostředí aplikace (ve složce shared, společné pro všechny releasy). Údaje databáze a pošty doplňuje panel. Proces aplikace a workery je načtou po restartu webu.'))
            ->modalWidth('6xl')
            ->slideOver()
            ->fillFormUsing(function ($record = null) use ($path) {
                try {
                    $f = $this->files()->read($this->getSite(), $path($record));

                    return ['content' => $f['content'], 'mtime' => $f['mtime']];
                } catch (AgentException $e) {
                    if ($e->errorCode === 'not_found') {
                        return ['content' => '', 'mtime' => 0]; // a new file
                    }
                    Toast::error(__('Soubor nejde otevřít'), $e->getMessage());

                    return ['content' => '', 'mtime' => -1];
                }
            })
            ->form(fn ($record = null) => [
                Hidden::make('mtime'),
                CodeIde::make('content')->hiddenLabel()->language(ManageFiles::language(basename($path($record))))->minHeight(560)
                    ->rules(['nullable', 'string', 'max:'.ManageFiles::MAX_SAVE]),
            ])
            ->modalSubmitActionLabel(__('Uložit'))
            ->action(fn (array $data, $record = null) => $this->attempt(__('Uložení selhalo'), function () use ($record, $data, $path) {
                if ((int) ($data['mtime'] ?? 0) < 0) {
                    return; // could not be opened, nothing to save
                }
                $this->files()->write($this->getSite(), $path($record), (string) ($data['content'] ?? ''), (int) ($data['mtime'] ?? 0) ?: null);
                Toast::success(__('Uloženo'), $path($record));
            }));
    }

    private function editEnv(): HeaderAction
    {
        /** @var HeaderAction */
        return $this->editAction((string) $this->envPath());
    }

    /** The site's shared env file (.env, .env.local for Symfony), or null. */
    public function envPath(): ?string
    {
        $site = $this->getSite();
        if ($site instanceof GameServer) {
            return null;
        }

        return match ($site->framework->value) {
            'symfony' => 'shared/.env.local',
            'laravel', 'php', 'node', 'nuxt', 'next', 'django', 'flask', 'fastapi', 'python' => 'shared/.env',
            default => null,
        };
    }

    public function render(): View
    {
        return view('livewire.sites.file-browser');
    }
}
