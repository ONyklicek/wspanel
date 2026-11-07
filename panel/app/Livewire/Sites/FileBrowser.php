<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\ManageFiles;
use App\Forms\Components\CodeIde;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Livewire\Attributes\Url;
use Livewire\Component;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireCore\Actions\BulkAction;
use NyonCode\WireCore\Actions\HeaderAction;
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

    public int $site;

    /** Folder shown, relative to the site's home ("." = the home). */
    #[Url(as: 'dir')]
    public string $path = '.';

    public ?string $error = null;

    /** @var list<string> problems of the files in the current upload */
    public array $uploadErrors = [];

    public function getSite(): Site
    {
        return Site::findOrFail($this->site);
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
        $crumbs = [['name' => $this->getSite()->domain, 'path' => '.']];
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
                $fail('Jen název, bez lomítek.');
            }
        };

        return $table
            ->stackedOnMobile()
            ->dataSource(new CollectionDataSource($this->rows()))
            ->columns([
                TextColumn::make('name')->label('Název')->searchable()->weight('medium')
                    ->icon(fn ($record) => match ($record->type) {
                        'dir' => 'outline:folder', 'link' => 'outline:link', default => 'outline:document'
                    })
                    ->iconColor(fn ($record) => $record->type === 'dir' ? 'warning' : 'gray')
                    ->description(fn ($record) => $record->type === 'link' ? '→ '.$record->target : null),
                TextColumn::make('size_label')->label('Velikost'),
                TextColumn::make('mtime_label')->label('Změněno'),
                TextColumn::make('mode')->label('Práva')->fontFamily('mono')->textColor('gray'),
            ])
            ->recordAction('open')
            ->actions([
                Action::make('open')->label('Otevřít')->icon('outline:folder-open')->color('gray')->onlyIcon()->tooltip('Otevřít')
                    ->visible(fn ($record) => in_array($record->type, ['dir', 'link'], true))
                    ->action(fn ($record) => $this->go($this->full($record->name))),
                $this->editAction(),
                Action::make('download')->label('Stáhnout')->icon('outline:arrow-down-tray')->color('gray')->onlyIcon()->tooltip('Stáhnout')
                    ->visible(fn ($record) => $record->type === 'file')
                    ->url(fn ($record) => route('site-files.download', [$this->site, 'path' => $this->full($record->name)]), true),
                ActionGroup::make([
                    Action::make('rename')->label('Přejmenovat / přesunout')->icon('outline:pencil')
                        ->fillFormUsing(fn ($record) => ['to' => $this->full($record->name)])
                        ->form([TextInput::make('to')->label('Nová cesta')->required()->maxLength(4096)
                            ->helperText('Relativně k domovu webu, např. current/public/obrazky/logo.png')])
                        ->action(fn ($record, array $data) => $this->attempt('Přejmenování selhalo', function () use ($record, $data) {
                            $this->files()->rename($this->getSite(), $this->full($record->name), $data['to']);
                            Toast::success('Přejmenováno');
                        })),
                    Action::make('chmod')->label('Oprávnění')->icon('outline:lock-closed')
                        ->fillFormUsing(fn ($record) => ['mode' => ltrim((string) $record->mode, '0') ?: '0'])
                        ->form([TextInput::make('mode')->label('Práva (osmičkově)')->required()->rules(['regex:/^[0-7]{3}$/'])
                            ->helperText('Např. 644 pro soubory, 755 pro složky a spustitelné skripty.')])
                        ->action(fn ($record, array $data) => $this->attempt('Změna práv selhala', function () use ($record, $data) {
                            $this->files()->chmod($this->getSite(), $this->full($record->name), $data['mode']);
                            Toast::success('Oprávnění změněna');
                        })),
                    Action::make('extract')->label('Rozbalit sem')->icon('outline:archive-box-arrow-down')
                        ->visible(fn ($record) => $record->type === 'file' && str_ends_with(strtolower($record->name), '.zip'))
                        ->fillFormUsing(fn ($record) => ['to' => $this->full(pathinfo($record->name, PATHINFO_FILENAME))])
                        ->form([TextInput::make('to')->label('Do složky')->required()])
                        ->action(fn ($record, array $data) => $this->attempt('Rozbalení selhalo', function () use ($record, $data) {
                            $n = $this->files()->extract($this->getSite(), $this->full($record->name), $data['to']);
                            Toast::success("Rozbaleno {$n} souborů");
                        })),
                    Action::make('delete')->label('Smazat')->icon('outline:trash')->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(fn ($record) => 'Smazat '.$record->name.'?')
                        ->modalDescription(fn ($record) => $record->type === 'dir' ? 'Smaže složku i celý její obsah. Nejde to vrátit.' : 'Nejde to vrátit.')
                        ->action(fn ($record) => $this->attempt('Smazání selhalo', function () use ($record) {
                            $this->files()->delete($this->getSite(), $this->full($record->name));
                            Toast::success('Smazáno');
                        })),
                ]),
            ])
            ->bulkActions([
                BulkAction::make('compress')->label('Zabalit do zip')->icon('outline:archive-box')
                    ->form([TextInput::make('to')->label('Název archivu')->default('archiv.zip')->required()->rules(['regex:/\.zip$/i', $nameRule])])
                    ->action(fn ($records, array $data) => $this->attempt('Zabalení selhalo', function () use ($records, $data) {
                        $n = $this->files()->compress($this->getSite(), $records->map(fn ($r) => $this->full($r->name))->values()->all(), $this->full($data['to']));
                        Toast::success("Zabaleno {$n} souborů do {$data['to']}");
                    })),
            ])
            ->headerActions(array_values(array_filter([
                $this->envPath() ? $this->editEnv() : null,
                HeaderAction::make('upload')->label('Nahrát')->icon('outline:arrow-up-tray')
                    ->modalDescription('Nahraje soubory do aktuální složky (každý nejvýš 512 MB; větší přes SFTP).')
                    ->form([
                        Toggle::make('replace')->label('Přepsat existující soubory se stejným názvem')->live(),
                        // Each file goes straight to the agent when the form is submitted.
                        FileUpload::make('files')->label('Soubory')->maxFiles(50)->maxSize(524288)->required()
                            ->storeFileUsing(function ($file, $get) {
                                $name = (string) $file->getClientOriginalName();
                                if (! ManageFiles::validName($name)) {
                                    $this->uploadErrors[] = "{$name}: neplatný název";

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
                            ? Toast::success("Nahráno {$done} souborů")
                            : Toast::error("Nahráno {$done}, některé soubory selhaly", implode("\n", $this->uploadErrors));
                        $this->uploadErrors = [];
                        $this->resetTable();
                    }),
                HeaderAction::make('newFile')->label('Nový soubor')->icon('outline:document-plus')->color('gray')
                    ->form([TextInput::make('name')->label('Název')->required()->rules([$nameRule])])
                    ->action(fn (array $data) => $this->attempt('Soubor se nepodařilo vytvořit', function () use ($data) {
                        $this->files()->write($this->getSite(), $this->full($data['name']), '');
                        Toast::success('Soubor vytvořen');
                    })),
                HeaderAction::make('newFolder')->label('Nová složka')->icon('outline:folder-plus')->color('gray')
                    ->form([TextInput::make('name')->label('Název')->required()->rules([$nameRule])])
                    ->action(fn (array $data) => $this->attempt('Složku se nepodařilo vytvořit', function () use ($data) {
                        $this->files()->mkdir($this->getSite(), $this->full($data['name']));
                        Toast::success('Složka vytvořena');
                    })),
            ])))
            ->emptyState(
                $this->error ? 'Složku se nepodařilo načíst' : 'Prázdná složka',
                $this->error ?? 'Nahrajte soubory nebo vytvořte nový.',
                'outline:folder-open',
            )
            ->paginated(false);
    }

    /** The editor, for the clicked row (path null) or a fixed file. */
    private function editAction(?string $fixed = null): Action|HeaderAction
    {
        $path = fn ($record = null) => $fixed ?? $this->full($record->name);
        $action = $fixed === null
            ? Action::make('edit')->label('Upravit')->icon('outline:pencil-square')->color('gray')->onlyIcon()->tooltip('Upravit')
                ->visible(fn ($record) => $record->type === 'file' && (int) $record->size <= ManageFiles::MAX_EDIT)
            : HeaderAction::make('editEnv')->label('Upravit '.basename($fixed))->icon('outline:key')->color('gray');

        return $action
            ->modalHeading(fn ($record = null) => $path($record))
            ->modalDescription($fixed === null ? null : 'Proměnné prostředí aplikace (ve složce shared, společné pro všechny releasy). Údaje databáze a pošty doplňuje panel. Proces aplikace a workery je načtou po restartu webu.')
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
                    Toast::error('Soubor nejde otevřít', $e->getMessage());

                    return ['content' => '', 'mtime' => -1];
                }
            })
            ->form(fn ($record = null) => [
                Hidden::make('mtime'),
                CodeIde::make('content')->hiddenLabel()->language(ManageFiles::language(basename($path($record))))->minHeight(560)
                    ->rules(['nullable', 'string', 'max:'.ManageFiles::MAX_SAVE]),
            ])
            ->modalSubmitActionLabel('Uložit')
            ->action(fn (array $data, $record = null) => $this->attempt('Uložení selhalo', function () use ($record, $data, $path) {
                if ((int) ($data['mtime'] ?? 0) < 0) {
                    return; // could not be opened, nothing to save
                }
                $this->files()->write($this->getSite(), $path($record), (string) ($data['content'] ?? ''), (int) ($data['mtime'] ?? 0) ?: null);
                Toast::success('Uloženo', $path($record));
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
        return match ($this->getSite()->framework->value) {
            'symfony' => 'shared/.env.local',
            'laravel', 'php', 'node', 'nuxt', 'next' => 'shared/.env',
            default => null,
        };
    }

    public function render(): View
    {
        return view('livewire.sites.file-browser');
    }
}
