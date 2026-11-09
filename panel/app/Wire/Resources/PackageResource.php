<?php

namespace App\Wire\Resources;

use App\Actions\Clients\ManageClients;
use App\Enums\SiteMode;
use App\Enums\SshAccess;
use App\Livewire\Clients\ListPackages;
use App\Models\Package;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Toast;
use App\Wire\Forms\Options;
use Illuminate\Support\Facades\Gate;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Core\Resources\Concerns\DescribesRecords;
use NyonCode\WireCore\Core\Resources\Contracts\DescribesResource;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WireCore\Foundation\Routing\RoutePage;
use NyonCode\WireCore\Foundation\Schema\Grid;
use NyonCode\WireCore\Foundation\Schema\Section;
use NyonCode\WireForms\Components\CheckboxList;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WirePanels\Resources\Contracts\ProvidesResourceTable;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Table;

/** Hosting packages: named sets of limits that clients are given. */
final class PackageResource implements DescribesResource, ProvidesNavigation, ProvidesPages, ProvidesResourceTable
{
    use DescribesRecords;

    /**
     * Numeric limits as shown in the form: column => label.
     *
     * @var array<string, string>
     */
    public const NUMERIC = [
        'max_sites' => 'Weby',
        'max_aliases' => 'Aliasy domén',
        'max_databases' => 'Databáze',
        'max_db_size_mb' => 'Velikost databází (MB)',
        'disk_quota_mb' => 'Místo na disku (MB)',
        'max_cron' => 'Naplánované úlohy',
        'max_workers' => 'Workery',
        'fpm_children' => 'Souběžné PHP procesy na web',
        'max_valkey_mb' => 'Valkey na web (MB, 0 = ne)',
        'max_mailboxes' => 'Poštovní schránky',
        'max_mail_quota_mb' => 'Místo pro poštu (MB)',
        'backups_keep' => 'Uchovávat záloh',
        'max_game_servers' => 'Herní servery',
        'max_game_memory_mb' => 'Paměť herních serverů celkem (MB)',
    ];

    public static function modelClass(): ?string
    {
        return Package::class;
    }

    public static function key(): string
    {
        return 'packages';
    }

    public static function label(): string
    {
        return __('Balíček');
    }

    public static function pluralLabel(): string
    {
        return __('Balíčky');
    }

    public static function pages(): array
    {
        return ['index' => RoutePage::make(ListPackages::class)->permission('clients')];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make(__('Balíčky'))->icon('outline:cube')->parent(ClientResource::class)->sort(10)
            ->visible(fn (): bool => Gate::allows('clients'));
    }

    /** Fields of the package form, shared by create and edit. */
    public static function formSchema(): array
    {
        $numeric = [];
        foreach (self::NUMERIC as $column => $label) {
            $numeric[] = TextInput::make($column)->label(__($label))->integer()->minValue(0)
                ->rules(['nullable', 'integer', 'min:0', 'max:10000000'])->placeholder(__('neomezeno'));
        }

        return [
            Grid::make()->columns(2)->schema([
                TextInput::make('name')->label(__('Název'))->required()->maxLength(64)->rules(['max:64']),
                TextInput::make('description')->label(__('Popis'))->maxLength(255)->rules(['max:255']),
            ]),
            Section::make('limits')->label(__('Limity (prázdné = neomezeno)'))->schema([
                Grid::make()->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->schema($numeric),
            ]),
            Section::make('features')->label(__('Co smí'))->schema([
                Select::make('ssh')->label('SSH / SFTP')->options(collect(SshAccess::cases())->mapWithKeys(fn (SshAccess $a) => [$a->value => $a->getLabel()])->all())
                    ->rules([Options::rule()])->default(SshAccess::Shell->value)->required(),
                CheckboxList::make('allowed_modes')->label(__('Režimy webu (nic = všechny)'))
                    ->options(collect(SiteMode::cases())->mapWithKeys(fn (SiteMode $m) => [$m->value => $m->getLabel()])->all()),
                CheckboxList::make('allowed_php')->label(__('Verze PHP (nic = všechny nainstalované)'))
                    ->options(fn () => app(SystemInfo::class)->phpVersionOptions()),
                Toggle::make('offsite')->label(__('Off-site kopie záloh'))->default(true),
            ]),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function normalize(array $data): array
    {
        foreach (array_keys(self::NUMERIC) as $column) {
            $data[$column] = ($data[$column] ?? null) === null || $data[$column] === '' ? null : (int) $data[$column];
        }
        $data['allowed_modes'] = array_values($data['allowed_modes'] ?? []) ?: null;
        $data['allowed_php'] = array_values($data['allowed_php'] ?? []) ?: null;

        return $data;
    }

    public function table(Table $table): Table
    {
        $limit = fn (?int $value, string $unit = '') => $value === null ? '∞' : $value.$unit;

        return $table
            ->stackedOnMobile()
            ->query(Package::query()->withCount('clients'))
            ->columns([
                TextColumn::make('name')->label(__('Balíček'))->weight('medium')->searchable()->sortable()
                    ->description(fn (Package $record) => $record->description),
                TextColumn::make('max_sites')->label(__('Weby'))->state(fn (Package $record) => $limit($record->max_sites)),
                TextColumn::make('max_databases')->label(__('Databáze'))->state(fn (Package $record) => $limit($record->max_databases)),
                TextColumn::make('disk_quota_mb')->label(__('Disk'))->state(fn (Package $record) => $record->disk_quota_mb === null ? '∞' : round($record->disk_quota_mb / 1024, 1).' GB'),
                BadgeColumn::make('ssh')->label('SSH'),
                TextColumn::make('clients_count')->label(__('Klientů'))->sortable(),
            ])
            ->actions([
                Action::make('edit')->label(__('Upravit'))->icon('outline:pencil-square')->onlyIcon()->tooltip(__('Upravit balíček'))
                    ->modalHeading(fn (Package $record) => __('Balíček :name', ['name' => $record->name]))
                    ->modalWidth('4xl')
                    ->fillFormUsing(fn (Package $record) => $record->only(array_merge(['name', 'description', 'offsite', 'allowed_modes', 'allowed_php'], array_keys(self::NUMERIC)))
                        + ['ssh' => $record->ssh?->value, 'allowed_modes' => $record->allowed_modes ?? [], 'allowed_php' => $record->allowed_php ?? []])
                    ->form(self::formSchema())
                    ->action(function (Package $record, array $data) {
                        $record->update(self::normalize($data));
                        $failed = [];
                        foreach ($record->clients as $client) {
                            try {
                                app(ManageClients::class)->applyToServer($client);
                            } catch (AgentException $e) {
                                $failed[] = $client->name.': '.$e->getMessage();
                            }
                        }
                        $failed === []
                            ? Toast::success(__('Balíček uložen'), __('Změna platí hned pro všechny jeho klienty.'))
                            : Toast::warning(__('Balíček uložen, u některých klientů se server nepodařilo upravit'), implode("\n", $failed));
                    }),
                Action::make('delete')->label(__('Smazat'))->icon('outline:trash')->color('danger')->onlyIcon()->tooltip(__('Smazat balíček'))
                    ->visible(fn (Package $record) => $record->clients_count === 0)
                    ->requiresConfirmation()
                    ->action(function (Package $record) {
                        $record->delete();
                        Toast::success(__('Balíček smazán'));
                    }),
            ])
            ->emptyState(__('Zatím žádný balíček'), __('Balíček je sada limitů (weby, databáze, disk…), kterou dáte klientům.'), 'outline:cube')
            ->defaultSort('name');
    }
}
