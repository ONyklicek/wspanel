<?php

namespace App\Filament\Resources\CronJobs\Pages;

use App\Actions\ManageCronJobs;
use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Filament\Resources\CronJobs\CronJobResource;
use App\Models\Site;
use App\Services\Agent\AgentException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ListCronJobs extends ListRecords
{
    protected static string $resource = CronJobResource::class;

    protected ?string $subheading = 'Úlohy běží pod uživatelem webu se stejným PHP jako web. Laravel scheduler patří mezi workery webu (schedule:work), ne sem.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Nová úloha')
                ->icon(Heroicon::OutlinedPlus)
                ->schema(fn () => self::taskFields())
                ->action(function (array $data) {
                    try {
                        app(ManageCronJobs::class)->create(Site::findOrFail($data['site_id']), $data);
                        Notification::make()->title('Úloha naplánována')->success()->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Úlohu se nepodařilo naplánovat')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }

    /** @return array<int, mixed> */
    public static function taskFields(bool $editing = false): array
    {
        $kinds = fn (?Site $site) => match (true) {
            $site === null => [],
            $site->framework === Framework::Laravel => ['artisan' => 'php artisan …', 'php' => 'PHP skript webu'],
            $site->framework === Framework::Symfony => ['console' => 'php bin/console …', 'php' => 'PHP skript webu'],
            default => ['php' => 'PHP skript webu'],
        };

        return [
            Select::make('site_id')
                ->label('Web')
                ->options(fn () => Site::query()->where('status', 'active')->where('mode', '!=', SiteMode::Node)->orderBy('domain')->pluck('domain', 'id')->all())
                ->required()
                ->live()
                ->disabled($editing)
                ->dehydrated(),
            Select::make('kind')
                ->label('Typ')
                ->options(fn (Get $get) => $kinds(Site::find($get('site_id'))))
                ->required()
                ->live(),
            TextInput::make('command')
                ->label(fn (Get $get) => $get('kind') === 'php' ? 'Skript (relativně k releasu)' : 'Příkaz a argumenty')
                ->placeholder(fn (Get $get) => $get('kind') === 'php' ? 'bin/report.php' : 'backup:run --only-db')
                ->required()
                ->regex('/^[A-Za-z0-9:_.\/=@,+\- ]+$/')
                ->validationMessages(['regex' => 'Jen písmena, číslice, mezery a :_./=@,+- (žádné uvozovky ani shellové znaky).']),
            TextInput::make('schedule')
                ->label('Rozvrh (cron)')
                ->placeholder('0 3 * * *')
                ->required()
                ->regex('/^\s*\S+(\s+\S+){4}\s*$/')
                ->helperText('minuta hodina den měsíc den-v-týdnu, např. */15 * * * * nebo 0 3 * * 1-5'),
        ];
    }
}
