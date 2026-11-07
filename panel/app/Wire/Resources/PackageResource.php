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
        'max_mailboxes' => 'Poštovní schránky',
        'max_mail_quota_mb' => 'Místo pro poštu (MB)',
        'backups_keep' => 'Uchovávat záloh',
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
        return 'Balíček';
    }

    public static function pluralLabel(): string
    {
        return 'Balíčky';
    }

    public static function pages(): array
    {
        return ['index' => RoutePage::make(ListPackages::class)->permission('admin')];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make('Balíčky')->icon('outline:cube')->parent(ClientResource::class)->sort(10)
            ->visible(fn (): bool => Gate::allows('admin'));
    }

    /** Fields of the package form, shared by create and edit. */
    public static function formSchema(): array
    {
        $numeric = [];
        foreach (self::NUMERIC as $column => $label) {
            $numeric[] = TextInput::make($column)->label($label)->integer()->minValue(0)
                ->rules(['nullable', 'integer', 'min:0', 'max:10000000'])->placeholder('neomezeno');
        }

        return [
            Grid::make()->columns(2)->schema([
                TextInput::make('name')->label('Název')->required()->maxLength(64)->rules(['max:64']),
                TextInput::make('description')->label('Popis')->maxLength(255)->rules(['max:255']),
            ]),
            Section::make('limits')->label('Limity (prázdné = neomezeno)')->schema([
                Grid::make()->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->schema($numeric),
            ]),
            Section::make('features')->label('Co smí')->schema([
                Select::make('ssh')->label('SSH / SFTP')->options(collect(SshAccess::cases())->mapWithKeys(fn (SshAccess $a) => [$a->value => $a->getLabel()])->all())
                    ->rules([Options::rule()])->default(SshAccess::Shell->value)->required(),
                CheckboxList::make('allowed_modes')->label('Režimy webu (nic = všechny)')
                    ->options(collect(SiteMode::cases())->mapWithKeys(fn (SiteMode $m) => [$m->value => $m->getLabel()])->all()),
                CheckboxList::make('allowed_php')->label('Verze PHP (nic = všechny nainstalované)')
                    ->options(fn () => app(SystemInfo::class)->phpVersionOptions()),
                Toggle::make('offsite')->label('Off-site kopie záloh')->default(true),
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
                TextColumn::make('name')->label('Balíček')->weight('medium')->searchable()->sortable()
                    ->description(fn (Package $record) => $record->description),
                TextColumn::make('max_sites')->label('Weby')->state(fn (Package $record) => $limit($record->max_sites)),
                TextColumn::make('max_databases')->label('Databáze')->state(fn (Package $record) => $limit($record->max_databases)),
                TextColumn::make('disk_quota_mb')->label('Disk')->state(fn (Package $record) => $record->disk_quota_mb === null ? '∞' : round($record->disk_quota_mb / 1024, 1).' GB'),
                BadgeColumn::make('ssh')->label('SSH'),
                TextColumn::make('clients_count')->label('Klientů')->sortable(),
            ])
            ->actions([
                Action::make('edit')->label('Upravit')->icon('outline:pencil-square')->onlyIcon()->tooltip('Upravit balíček')
                    ->modalHeading(fn (Package $record) => "Balíček {$record->name}")
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
                            ? Toast::success('Balíček uložen', 'Změna platí hned pro všechny jeho klienty.')
                            : Toast::warning('Balíček uložen, u některých klientů se server nepodařilo upravit', implode("\n", $failed));
                    }),
                Action::make('delete')->label('Smazat')->icon('outline:trash')->color('danger')->onlyIcon()->tooltip('Smazat balíček')
                    ->visible(fn (Package $record) => $record->clients_count === 0)
                    ->requiresConfirmation()
                    ->action(function (Package $record) {
                        $record->delete();
                        Toast::success('Balíček smazán');
                    }),
            ])
            ->emptyState('Zatím žádný balíček', 'Balíček je sada limitů (weby, databáze, disk…), kterou dáte klientům.', 'outline:cube')
            ->defaultSort('name');
    }
}
