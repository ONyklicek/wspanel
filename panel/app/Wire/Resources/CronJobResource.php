<?php

namespace App\Wire\Resources;

use App\Actions\ManageCronJobs;
use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Livewire\Sites\ListCronJobs;
use App\Models\CronJob;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\CronDescription;
use App\Support\Toast;
use App\Wire\Actions\Action;
use App\Wire\Forms\Options;
use Illuminate\Support\Carbon;
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
        return __('Úloha');
    }

    public static function pluralLabel(): string
    {
        return __('Naplánované úlohy');
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
            $site->framework === Framework::Django => ['manage' => 'python manage.py …', 'python' => __('Python skript webu')],
            $site->mode === SiteMode::Python => ['python' => __('Python skript webu')],
            default => ['php' => 'PHP skript webu'],
        };

        return [
            Select::make('site_id')->rules([Options::rule()])->label(__('Web'))->required()->live()->disabled($editing)
                ->options(Site::query()->where('status', 'active')->where('mode', '!=', SiteMode::Node)->orderBy('domain')->pluck('domain', 'id')->all()),
            Select::make('kind')->rules([Options::rule()])->label(__('Typ'))->required()->live()->options(fn ($get) => $kinds(Site::find($get('site_id')))),
            TextInput::make('command')
                ->label(__('Příkaz a argumenty (u skriptu cesta relativně k releasu)'))
                ->placeholder('backup:run --only-db')
                ->required()
                ->rules(['regex:/^[A-Za-z0-9:_.\/=@,+\- ]+$/'])
                ->validationMessages(['regex' => __('Jen písmena, číslice, mezery a :_./=@,+- (žádné uvozovky ani shellové znaky).')]),
            TextInput::make('schedule')->label(__('Rozvrh (cron)'))->placeholder('0 3 * * *')->required()
                ->rules(['regex:/^\s*\S+(\s+\S+){4}\s*$/'])
                ->helperText(__('minuta hodina den měsíc den-v-týdnu, např. */15 * * * * nebo 0 3 * * 1-5')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(CronJob::query()->with('site'))
            ->columns([
                TextColumn::make('command')->label(__('Úloha'))->state(fn (CronJob $record) => $record->commandLine())->fontFamily('mono')->weight('medium')
                    ->description(fn (CronJob $record) => $record->site?->domain),
                TextColumn::make('schedule')->label(__('Rozvrh'))
                    ->state(fn (CronJob $record) => CronDescription::cs($record->schedule) ?? $record->schedule)
                    ->description(fn (CronJob $record) => CronDescription::cs($record->schedule) !== null ? $record->schedule : $record->calendar),
                BadgeColumn::make('last')->label(__('Poslední běh'))
                    ->state(fn (CronJob $record) => $record->last_run && $record->last_run !== 'n/a' ? ($record->last_result === 'success' ? 'OK' : ($record->last_result ?? '?')) : __('zatím neběžela'))
                    ->colors(['OK' => 'success', __('zatím neběžela') => 'gray'])
                    ->color('danger')
                    ->description(fn (CronJob $record) => self::when($record->last_run)?->diffForHumans()),
                TextColumn::make('next_run')->label(__('Další běh'))->placeholder('—')
                    ->state(fn (CronJob $record) => self::when($record->next_run)?->format('j. n. H:i'))
                    ->description(fn (CronJob $record) => self::when($record->next_run)?->diffForHumans()),
            ])
            ->filters([
                SelectFilter::make('site_id')->label(__('Web'))->options(fn () => Site::query()->orderBy('domain')->pluck('domain', 'id')->all()),
            ])
            ->actions([
                Action::make('edit')->label(__('Upravit'))->icon('outline:pencil-square')->color('gray')->onlyIcon()->tooltip(__('Upravit'))
                    ->fillFormUsing(fn (CronJob $record) => ['site_id' => $record->site_id, 'kind' => $record->kind, 'command' => implode(' ', $record->args), 'schedule' => $record->schedule])
                    ->form(self::fields(editing: true))
                    ->action(function (CronJob $record, array $data) {
                        try {
                            app(ManageCronJobs::class)->update($record, $data);
                            Toast::success(__('Úloha upravena'));
                        } catch (AgentException $e) {
                            Toast::error(__('Úpravu se nepodařilo uložit'), $e->getMessage());
                        }
                    }),
                Action::make('delete')->label(__('Smazat'))->icon('outline:trash')->color('danger')->onlyIcon()->tooltip(__('Smazat'))->requiresConfirmation()
                    ->action(function (CronJob $record) {
                        try {
                            app(ManageCronJobs::class)->delete($record);
                            Toast::success(__('Úloha smazána'));
                        } catch (AgentException $e) {
                            Toast::error(__('Smazání selhalo'), $e->getMessage());
                        }
                    }),
            ])
            ->emptyState(__('Žádné naplánované úlohy'), __('Laravel plánovač (schedule:work) přidáte jako worker u webu; sem patří samostatné úlohy podle cronu.'), 'outline:clock');
    }

    /** systemd prints "Sat 2026-11-07 17:43:00 UTC"; null for n/a or nothing. */
    private static function when(?string $systemd): ?Carbon
    {
        if ($systemd === null || $systemd === '' || $systemd === 'n/a') {
            return null;
        }
        try {
            return Carbon::parse((string) preg_replace('/^\w{3} /', '', $systemd))->timezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
