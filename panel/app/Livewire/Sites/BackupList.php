<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\ManageSiteBackups;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Livewire\Component;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Actions\HeaderAction;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Concerns\WithTable;
use NyonCode\WireTable\Data\CollectionDataSource;
use NyonCode\WireTable\Table;

/** The site's whole backups: list, back up now, restore, schedule. */
class BackupList extends Component
{
    use WithTable;

    public int $site;

    public ?string $error = null;

    /** @var array{frequency: string, keep: int, databases: bool} */
    public array $schedule = ['frequency' => 'off', 'keep' => 7, 'databases' => true];

    public function getSite(): Site
    {
        return Site::findOrFail($this->site);
    }

    private function backups(): ManageSiteBackups
    {
        return app(ManageSiteBackups::class);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        try {
            $this->error = null;
            $out = $this->backups()->list($this->getSite());
        } catch (AgentException $e) {
            $this->error = $e->getMessage();

            return [];
        }
        $this->schedule = $out['schedule'];

        return array_map(fn (array $b) => [
            'id' => $b['file'],
            'file' => $b['file'],
            'created_label' => $b['created']->format('j. n. Y H:i'),
            'kind' => $b['pre_restore'] ? 'Před obnovou' : 'Záloha',
            'size_label' => Number::fileSize($b['size'], 1),
        ], $out['backups']);
    }

    private function attempt(string $failure, callable $fn): void
    {
        try {
            $fn();
        } catch (AgentException $e) {
            Toast::error($failure, $e->getMessage());
        }
        if (method_exists($this, 'invalidateTable')) {
            $this->invalidateTable();
        }
    }

    public function scheduleLabel(): string
    {
        if ($this->schedule['frequency'] === 'off') {
            return 'Automatické zálohy jsou vypnuté.';
        }

        return sprintf('Automaticky %s (kolem 2:30), uchovává se %d posledních%s.',
            $this->schedule['frequency'] === 'daily' ? 'každý den' : 'každou neděli',
            $this->schedule['keep'],
            $this->schedule['databases'] ? ', včetně databází' : ', bez databází');
    }

    public function table(Table $table): Table
    {
        $site = $this->getSite();
        $hasDatabases = $site->databases()->exists();

        return $table
            ->stackedOnMobile()
            ->dataSource(new CollectionDataSource($this->rows()))
            ->columns([
                TextColumn::make('created_label')->label('Vytvořeno')->weight('medium')
                    ->description(fn ($record) => $record->file),
                BadgeColumn::make('kind')->label('Druh')->colors(['Záloha' => 'success', 'Před obnovou' => 'warning']),
                TextColumn::make('size_label')->label('Velikost'),
            ])
            ->actions([
                Action::make('download')->label('Stáhnout')->icon('outline:arrow-down-tray')->color('gray')->onlyIcon()->tooltip('Stáhnout')
                    ->url(fn ($record) => route('site-backups.download', [$this->site, $record->file]), true),
                Action::make('restore')->label('Obnovit')->icon('outline:arrow-uturn-left')->color('warning')
                    ->modalHeading(fn ($record) => 'Obnovit zálohu z '.$record->created_label.'?')
                    ->modalDescription('Před obnovou se uloží záloha současného stavu. Soubory se obnoví jako nový release (dosavadní zůstane k návratu přes Deploy), současná složka shared se ponechá vedle jako shared.before-restore-…. Databáze se přepíšou obsahem zálohy.')
                    ->form([
                        Toggle::make('files')->label('Obnovit soubory (release a shared)')->default(true),
                        Toggle::make('databases')->label('Obnovit databáze')->default($hasDatabases),
                        TextInput::make('confirm')->label('Pro potvrzení napište doménu webu')->placeholder($site->domain)->required()
                            ->rules([function (string $attribute, mixed $value, \Closure $fail) use ($site) {
                                if ($value !== $site->domain) {
                                    $fail('Napište přesně '.$site->domain.'.');
                                }
                            }]),
                    ])
                    ->modalSubmitActionLabel('Obnovit')
                    ->action(fn ($record, array $data) => $this->attempt('Obnova selhala', function () use ($record, $data) {
                        if (! $data['files'] && ! $data['databases']) {
                            Toast::warning('Nic k obnovení', 'Zvolte soubory, databáze nebo obojí.');

                            return;
                        }
                        $out = $this->backups()->restore($this->getSite(), $record->file, (bool) $data['files'], (bool) $data['databases']);
                        if ($out === null) {
                            Toast::warning('Obnova běží na pozadí', 'Je to velká záloha; výsledek uvidíte v historii deployů a v auditu.');

                            return;
                        }
                        $notes = [];
                        if (! empty($out['release'])) {
                            $notes[] = 'Nový release '.$out['release'].(($out['healthy'] ?? false) ? ', web odpovídá.' : ', ale web neodpovídá: '.($out['health_error'] ?? ''));
                        }
                        if (! empty($out['databases'])) {
                            $notes[] = 'Databáze: '.implode(', ', $out['databases']).'.';
                        }
                        if (! empty($out['skipped_databases'])) {
                            $notes[] = 'Vynechány (na serveru už nejsou): '.implode(', ', $out['skipped_databases']).'.';
                        }
                        $notes[] = 'Stav před obnovou: '.$out['snapshot'].'.';
                        ($out['healthy'] ?? true)
                            ? Toast::success('Záloha obnovena', implode("\n", $notes))
                            : Toast::error('Záloha obnovena, web ale neodpovídá', implode("\n", $notes));
                    })),
                Action::make('delete')->label('Smazat')->icon('outline:trash')->color('danger')->onlyIcon()->tooltip('Smazat')
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => 'Smazat zálohu z '.$record->created_label.'?')
                    ->modalDescription('Nejde to vrátit.')
                    ->action(fn ($record) => $this->attempt('Smazání selhalo', function () use ($record) {
                        $this->backups()->delete($this->getSite(), $record->file);
                        Toast::success('Záloha smazána');
                    })),
            ])
            ->headerActions([
                HeaderAction::make('backupNow')->label('Zálohovat nyní')->icon('outline:archive-box-arrow-down')
                    ->modalHeading('Zálohovat web')
                    ->modalDescription('Zabalí aktuální release, složku shared a databáze webu. U velkého webu to může chvíli trvat.')
                    ->form([
                        Toggle::make('databases')->label('Včetně databází webu')->default($hasDatabases)->disabled(! $hasDatabases)
                            ->helperText($hasDatabases ? implode(', ', $site->databases()->orderBy('name')->pluck('name')->all()) : 'Web nemá žádnou databázi.'),
                    ])
                    ->modalSubmitActionLabel('Zálohovat')
                    ->action(fn (array $data) => $this->attempt('Záloha selhala', function () use ($data) {
                        $this->backups()->backupNow($this->getSite(), (bool) ($data['databases'] ?? false))
                            ? Toast::success('Záloha vytvořena')
                            : Toast::warning('Záloha běží na pozadí', 'Objeví se v seznamu, až doběhne.');
                    })),
                HeaderAction::make('schedule')->label('Plán záloh')->icon('outline:clock')->color('gray')
                    ->fillFormUsing(fn () => $this->schedule)
                    ->form([
                        Select::make('frequency')->label('Automaticky')->options(ManageSiteBackups::FREQUENCIES)->required()
                            ->rules(['in:'.implode(',', array_keys(ManageSiteBackups::FREQUENCIES))]),
                        TextInput::make('keep')->label('Uchovat posledních')->numeric()->required()->rules(['integer', 'min:1', 'max:90'])
                            ->helperText('Starší zálohy se po každé automatické záloze smažou (zálohy před obnovou se počítají také).'),
                        Toggle::make('databases')->label('Včetně databází webu')->disabled(! $hasDatabases),
                    ])
                    ->modalSubmitActionLabel('Uložit')
                    ->action(fn (array $data) => $this->attempt('Plán se nepodařilo uložit', function () use ($data) {
                        $this->backups()->schedule($this->getSite(), $data['frequency'], (int) $data['keep'], (bool) ($data['databases'] ?? false));
                        Toast::success('Plán záloh uložen');
                    })),
            ])
            ->emptyState(
                $this->error ? 'Zálohy se nepodařilo načíst' : 'Zatím žádná záloha',
                $this->error ?? 'Vytvořte první zálohu nebo nastavte automatické zálohování.',
                'outline:archive-box',
            )
            ->paginated(false);
    }

    public function render(): View
    {
        return view('livewire.sites.backup-list');
    }
}
