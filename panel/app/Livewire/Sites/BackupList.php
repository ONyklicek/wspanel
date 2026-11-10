<?php

namespace App\Livewire\Sites;

use App\Actions\Server\ManageOffsite;
use App\Actions\Sites\ManageSiteBackups;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Lang;
use App\Support\Toast;
use App\Wire\Actions\Action;
use App\Wire\Actions\HeaderAction;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Livewire\Attributes\On;
use Livewire\Component;
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

    /** Off-site copies are on (the agent answered the listing). */
    public bool $offsite = false;

    /** Why the off-site copies could not be listed, if they could not. */
    public ?string $offsiteError = null;

    /** @var array{frequency: string, keep: int, databases: bool} */
    public array $schedule = ['frequency' => 'off', 'keep' => 7, 'databases' => true];

    /** A backup, restore or transfer ended (here or elsewhere): drawn again with the result. */
    #[On('agent-task-finished')]
    public function agentTaskEnded(): void {}

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
        $copies = $this->copies();

        $rows = array_map(fn (array $b) => [
            'id' => $b['file'],
            'file' => $b['file'],
            'created' => $b['created']->getTimestamp(),
            'created_label' => $b['created']->format('j. n. Y H:i'),
            'kind' => $b['pre_restore'] ? __('Před obnovou') : __('Záloha'),
            'size_label' => Number::fileSize($b['size'], 1),
            'local' => true,
            'offsite_label' => $b['pre_restore'] ? '—' : (isset($copies[$b['file']]) ? 'ano' : 'ne'),
        ], $out['backups']);

        // Copies whose local backup is gone (pruned, or a new server).
        $local = array_column($rows, 'file');
        foreach ($copies as $file => $copy) {
            if (in_array($file, $local, true)) {
                continue;
            }
            $created = preg_match('/-(\d{8}T\d{6}Z)\.tar\.gz$/', $file, $m)
                ? Carbon::createFromFormat('Ymd\THis\Z', $m[1], 'UTC')->timezone(config('app.timezone'))
                : $copy['modified'];
            $rows[] = [
                'id' => $file,
                'file' => $file,
                'created' => $created->getTimestamp(),
                'created_label' => $created->format('j. n. Y H:i'),
                'kind' => __('Jen mimo server'),
                'size_label' => Number::fileSize($copy['size'], 1),
                'local' => false,
                'offsite_label' => 'ano',
            ];
        }
        usort($rows, fn ($a, $b) => $b['created'] <=> $a['created']);

        return $rows;
    }

    /** @return array<string, array{size: int, modified: Carbon}> */
    private function copies(): array
    {
        $this->offsite = false;
        $this->offsiteError = null;
        try {
            $copies = app(ManageOffsite::class)->siteCopies($this->getSite());
            $this->offsite = true;

            return $copies;
        } catch (AgentException $e) {
            if ($e->errorCode !== 'conflict') { // conflict = not set up
                $this->offsite = true;
                $this->offsiteError = $e->getMessage();
            }

            return [];
        }
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
            return __('Automatické zálohy jsou vypnuté.');
        }

        return sprintf(__('Automaticky %s (kolem 2:30), uchovává se %d posledních%s.'),
            $this->schedule['frequency'] === 'daily' ? __('každý den') : __('každou neděli'),
            $this->schedule['keep'],
            $this->schedule['databases'] ? __(', včetně databází') : __(', bez databází'));
    }

    public function table(Table $table): Table
    {
        $site = $this->getSite();
        $hasDatabases = $site->databases()->exists();

        return $table
            ->stackedOnMobile()
            ->dataSource(new CollectionDataSource($this->rows()))
            ->columns([
                TextColumn::make('created_label')->label(__('Vytvořeno'))->weight('medium')
                    ->description(fn ($record) => $record->file),
                BadgeColumn::make('kind')->label(__('Druh'))->colors([__('Záloha') => 'success', __('Před obnovou') => 'warning', __('Jen mimo server') => 'info']),
                TextColumn::make('size_label')->label(__('Velikost')),
                BadgeColumn::make('offsite_label')->label(__('Mimo server'))->colors(['ano' => 'success', 'ne' => 'gray', '—' => 'gray'])
                    ->visible($this->offsite),
            ])
            ->actions([
                Action::make('download')->label(__('Stáhnout'))->icon('outline:arrow-down-tray')->color('gray')->onlyIcon()->tooltip(__('Stáhnout'))
                    ->visible(fn ($record) => (bool) $record->local)
                    ->url(fn ($record) => route('site-backups.download', [$this->site, $record->file]), true),
                Action::make('fetch')->label(__('Stáhnout na server'))->icon('outline:cloud-arrow-down')->color('info')
                    ->visible(fn ($record) => ! $record->local)
                    ->modalHeading(fn ($record) => __('Stáhnout zálohu z ').$record->created_label.' na server?')
                    ->modalDescription(__('Záloha se stáhne z úložiště mimo server vedle ostatních záloh webu; pak ji můžete obnovit nebo stáhnout. Přijme se jen kopie s platným podpisem tohoto serveru.'))
                    ->form([
                        Toggle::make('unverified')->label(__('Přijmout i kopii bez platného podpisu'))
                            ->helperText(__('Jen pro zálohy jiného serveru, jehož klíč nemáte. Kopii bez podpisu mohl kdokoli s přístupem k úložišti podvrhnout nebo změnit – obnovte ji, jen když jí věříte.')),
                    ])
                    ->modalSubmitActionLabel(__('Stáhnout'))
                    ->action(fn ($record, array $data) => $this->attempt(__('Stažení na server selhalo'), function () use ($record, $data) {
                        app(ManageOffsite::class)->fetchSiteBackup($this->getSite(), $record->file, (bool) ($data['unverified'] ?? false));
                    })),
                Action::make('upload')->label(__('Nahrát mimo server'))->icon('outline:cloud-arrow-up')->color('gray')->onlyIcon()->tooltip(__('Nahrát mimo server'))
                    ->visible(fn ($record) => $this->offsite && $this->offsiteError === null && $record->local && $record->offsite_label === 'ne')
                    ->action(fn ($record) => $this->attempt(__('Nahrání mimo server selhalo'), function () use ($record) {
                        app(ManageOffsite::class)->uploadSiteBackup($this->getSite(), $record->file);
                    })),
                Action::make('restore')->label(__('Obnovit'))->icon('outline:arrow-uturn-left')->color('warning')
                    ->visible(fn ($record) => (bool) $record->local)
                    ->modalHeading(fn ($record) => __('Obnovit zálohu z ').$record->created_label.'?')
                    ->modalDescription(__('Před obnovou se uloží záloha současného stavu. Soubory se obnoví jako nový release (dosavadní zůstane k návratu přes Deploy), současná složka shared se ponechá vedle jako shared.before-restore-…. Databáze se přepíšou obsahem zálohy.'))
                    ->form([
                        Toggle::make('files')->label(__('Obnovit soubory (release a shared)'))->default(true),
                        Toggle::make('databases')->label(__('Obnovit databáze'))->default($hasDatabases),
                        TextInput::make('confirm')->label(__('Pro potvrzení napište doménu webu'))->placeholder($site->domain)->required()
                            ->rules([function (string $attribute, mixed $value, \Closure $fail) use ($site) {
                                if ($value !== $site->domain) {
                                    $fail(__('Napište přesně ').$site->domain.'.');
                                }
                            }]),
                    ])
                    ->modalSubmitActionLabel(__('Obnovit'))
                    ->action(fn ($record, array $data) => $this->attempt(__('Obnova selhala'), function () use ($record, $data) {
                        if (! $data['files'] && ! $data['databases']) {
                            Toast::warning(__('Nic k obnovení'), __('Zvolte soubory, databáze nebo obojí.'));

                            return;
                        }
                        $this->backups()->restore($this->getSite(), $record->file, (bool) $data['files'], (bool) $data['databases']);
                    })),
                Action::make('delete')->label(__('Smazat'))->icon('outline:trash')->color('danger')->onlyIcon()->tooltip(__('Smazat'))
                    ->visible(fn ($record) => (bool) $record->local)
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => __('Smazat zálohu z ').$record->created_label.'?')
                    ->modalDescription(fn ($record) => $record->offsite_label === 'ano' ? __('Smaže se jen ze serveru; kopie mimo server zůstane.') : __('Nejde to vrátit.'))
                    ->action(fn ($record) => $this->attempt(__('Smazání selhalo'), function () use ($record) {
                        $this->backups()->delete($this->getSite(), $record->file);
                        Toast::success(__('Záloha smazána'));
                    })),
            ])
            ->headerActions([
                HeaderAction::make('backupNow')->label(__('Zálohovat nyní'))->icon('outline:archive-box-arrow-down')
                    ->modalHeading(__('Zálohovat web'))
                    ->modalDescription(__('Zabalí aktuální release, složku shared a databáze webu. U velkého webu to může chvíli trvat.'))
                    ->form([
                        Toggle::make('databases')->label(__('Včetně databází webu'))->default($hasDatabases)->disabled(! $hasDatabases)
                            ->helperText($hasDatabases ? implode(', ', $site->databases()->orderBy('name')->pluck('name')->all()) : __('Web nemá žádnou databázi.')),
                    ])
                    ->modalSubmitActionLabel(__('Zálohovat'))
                    ->action(fn (array $data) => $this->attempt(__('Záloha selhala'), function () use ($data) {
                        $this->backups()->backupNow($this->getSite(), (bool) ($data['databases'] ?? false));
                    })),
                HeaderAction::make('schedule')->label(__('Plán záloh'))->icon('outline:clock')->color('gray')
                    ->fillFormUsing(fn () => $this->schedule)
                    ->form([
                        Select::make('frequency')->label(__('Automaticky'))->options(Lang::values(ManageSiteBackups::FREQUENCIES))->required()
                            ->rules(['in:'.implode(',', array_keys(ManageSiteBackups::FREQUENCIES))]),
                        TextInput::make('keep')->label(__('Uchovat posledních'))->numeric()->required()->rules(['integer', 'min:1', 'max:90'])
                            ->helperText(__('Starší zálohy se po každé automatické záloze smažou (zálohy před obnovou se počítají také).')),
                        Toggle::make('databases')->label(__('Včetně databází webu'))->disabled(! $hasDatabases),
                    ])
                    ->modalSubmitActionLabel(__('Uložit'))
                    ->action(fn (array $data) => $this->attempt(__('Plán se nepodařilo uložit'), function () use ($data) {
                        $this->backups()->schedule($this->getSite(), $data['frequency'], (int) $data['keep'], (bool) ($data['databases'] ?? false));
                        Toast::success(__('Plán záloh uložen'));
                    })),
            ])
            ->emptyState(
                $this->error ? __('Zálohy se nepodařilo načíst') : __('Zatím žádná záloha'),
                $this->error ?? __('Vytvořte první zálohu nebo nastavte automatické zálohování.'),
                'outline:archive-box',
            )
            ->paginated(false);
    }

    public function render(): View
    {
        return view('livewire.sites.backup-list');
    }
}
