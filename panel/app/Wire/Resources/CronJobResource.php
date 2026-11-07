<?php

namespace App\Wire\Resources;

use App\Actions\ManageCronJobs;
use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Livewire\Sites\ListCronJobs;
use App\Models\CronJob;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Core\Resources\Concerns\DescribesRecords;
use NyonCode\WireCore\Core\Resources\Contracts\DescribesResource;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Resources\Contracts\ProvidesResourceTable;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Filters\SelectFilter;
use NyonCode\WireTable\Table;

final class CronJobResource implements DescribesResource, ProvidesNavigation, ProvidesPages, ProvidesResourceTable
{
    use DescribesRecords;

    public static function modelClass(): ?string
    {
        return CronJob::class;
    }

    public static function key(): string
    {
        return 'tasks';
    }

    public static function label(): string
    {
        return 'Úloha';
    }

    public static function pluralLabel(): string
    {
        return 'Naplánované úlohy';
    }

    public static function pages(): array
    {
        return ['index' => ListCronJobs::class];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make()->icon('outline:clock')->group('hosting')->sort(30);
    }

    /** @return list<mixed> */
    public static function fields(bool $editing = false): array
    {
        $kinds = fn (?Site $site) => match (true) {
            $site === null => [],
            $site->framework === Framework::Laravel => ['artisan' => 'php artisan …', 'php' => 'PHP skript webu'],
            $site->framework === Framework::Symfony => ['console' => 'php bin/console …', 'php' => 'PHP skript webu'],
            default => ['php' => 'PHP skript webu'],
        };

        return [
            Select::make('site_id')->label('Web')->required()->live()->disabled($editing)
                ->options(Site::query()->where('status', 'active')->where('mode', '!=', SiteMode::Node)->orderBy('domain')->pluck('domain', 'id')->all()),
            Select::make('kind')->label('Typ')->required()->live()->options(fn ($get) => $kinds(Site::find($get('site_id')))),
            TextInput::make('command')
                ->label('Příkaz a argumenty (u PHP skriptu cesta relativně k releasu)')
                ->placeholder('backup:run --only-db')
                ->required()
                ->rules(['regex:/^[A-Za-z0-9:_.\/=@,+\- ]+$/'])
                ->validationMessages(['regex' => 'Jen písmena, číslice, mezery a :_./=@,+- (žádné uvozovky ani shellové znaky).']),
            TextInput::make('schedule')->label('Rozvrh (cron)')->placeholder('0 3 * * *')->required()
                ->rules(['regex:/^\s*\S+(\s+\S+){4}\s*$/'])
                ->helperText('minuta hodina den měsíc den-v-týdnu, např. */15 * * * * nebo 0 3 * * 1-5'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(CronJob::query()->with('site'))
            ->columns([
                TextColumn::make('site_domain')->label('Web')->state(fn (CronJob $record) => $record->site?->domain),
                TextColumn::make('command')->label('Příkaz')->state(fn (CronJob $record) => $record->commandLine())->fontFamily('mono'),
                TextColumn::make('schedule')->label('Rozvrh')->fontFamily('mono')->description(fn (CronJob $record) => $record->calendar),
                BadgeColumn::make('last')->label('Poslední běh')
                    ->state(fn (CronJob $record) => $record->last_run && $record->last_run !== 'n/a' ? ($record->last_result === 'success' ? 'OK' : ($record->last_result ?? '?')) : 'zatím neběžela')
                    ->colors(['OK' => 'success', 'zatím neběžela' => 'gray'])
                    ->color('danger')
                    ->description(fn (CronJob $record) => $record->last_run && $record->last_run !== 'n/a' ? $record->last_run : null),
                TextColumn::make('next_run')->label('Další běh')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Web')->options(fn () => Site::query()->orderBy('domain')->pluck('domain', 'id')->all()),
            ])
            ->actions([
                Action::make('edit')->label('Upravit')->icon('outline:pencil-square')
                    ->fillFormUsing(fn (CronJob $record) => ['site_id' => $record->site_id, 'kind' => $record->kind, 'command' => implode(' ', $record->args), 'schedule' => $record->schedule])
                    ->form(self::fields(editing: true))
                    ->action(function (CronJob $record, array $data) {
                        try {
                            app(ManageCronJobs::class)->update($record, $data);
                            Toast::success('Úloha upravena');
                        } catch (AgentException $e) {
                            Toast::error('Úpravu se nepodařilo uložit', $e->getMessage());
                        }
                    }),
                Action::make('delete')->label('Smazat')->icon('outline:trash')->color('danger')->requiresConfirmation()
                    ->action(function (CronJob $record) {
                        try {
                            app(ManageCronJobs::class)->delete($record);
                            Toast::success('Úloha smazána');
                        } catch (AgentException $e) {
                            Toast::error('Smazání selhalo', $e->getMessage());
                        }
                    }),
            ])
            ->emptyState('Žádné naplánované úlohy', 'Laravel plánovač (schedule:work) přidáte jako worker u webu; sem patří samostatné úlohy podle cronu.', 'outline:clock');
    }
}
