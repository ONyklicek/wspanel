<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\InstallApp;
use App\Exceptions\LimitReachedException;
use App\Livewire\Concerns\RefreshesWhenTasksEnd;
use App\Livewire\Concerns\TranslatesPageText;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Lang;
use App\Support\Toast;
use App\Wire\Actions\Action;
use App\Wire\Forms\Options;
use App\Wire\Resources\SiteResource;
use App\Wire\Sites\SiteFields;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListSites extends ListPage
{
    use RefreshesWhenTasksEnd;
    use TranslatesPageText;

    protected static ?string $resource = SiteResource::class;

    protected function headerActions(): array
    {
        return [
            $this->createHeaderAction()?->label(__('Nový web')),
            Action::make('installApp')->label(__('Nová aplikace'))->icon('outline:sparkles')->color('gray')
                ->modalHeading(__('Web s aplikací jedním klikem'))
                ->modalDescription(__('Založí web, jeho databázi a aplikaci. Laravel a Symfony se nasadí z oficiální kostry jako běžný deploy, takže aktualizace a návrat zpět fungují jako u každého webu.'))
                ->form([
                    Radio::make('app')->label(__('Aplikace'))->options(Lang::values(InstallApp::APPS))->default('wordpress')->required()->rules([Options::rule(InstallApp::APPS)]),
                    SiteFields::domain(),
                    Select::make('php_version')->label('PHP')->options(fn () => app(SystemInfo::class)->phpVersionOptions())
                        ->default(fn () => app(SystemInfo::class)->defaultPhpVersion())->rules([Options::rule()])->required(),
                    // WordPress cannot do without one (MariaDB); Laravel and Symfony may (SQLite in the app).
                    Toggle::make('db_create')->label(__('Založit databázi'))->default(true)->live()
                        ->visible(fn ($get = null) => $get !== null && $get('app') !== 'wordpress' && app(SystemInfo::class)->dbEngines() !== [])
                        ->helperText(__('Údaje (host, jméno, heslo) zapíše server rovnou do .env webu.')),
                    Select::make('db_engine')->label(__('Engine'))->rules([Options::rule()])
                        ->options(fn ($get = null) => $this->appEngines($get === null ? null : (string) $get('app')))
                        ->default(fn () => array_key_first($this->appEngines(null)))
                        ->visible(fn ($get = null) => $get !== null && ($get('app') === 'wordpress' || $get('db_create')) && $this->appEngines((string) $get('app')) !== []),
                    TextInput::make('db_name')->label(__('Název databáze (i uživatel)'))->maxLength(32)
                        ->placeholder(fn ($get = null) => $get === null ? '' : SiteFields::databaseName((string) $get('domain')))
                        ->helperText(__('Prázdné = podle domény.'))
                        ->visible(fn ($get = null) => $get !== null && ($get('app') === 'wordpress' || $get('db_create')) && $this->appEngines((string) $get('app')) !== [])
                        ->rules(['nullable', 'max:32', 'regex:/^[a-z][a-z0-9_]{0,31}$/'])
                        ->validationMessages(['regex' => __('Malá písmena, číslice a podtržítko, začíná písmenem.')]),
                ])
                ->action(function (array $data) {
                    try {
                        $out = app(InstallApp::class)->handle($data);
                        Toast::success(__(':app na :domain', ['app' => __(InstallApp::APPS[$data['app']]), 'domain' => $out['site']->domain]), $out['note']);
                        $this->redirect(SiteResource::url('view', $out['site']));
                    } catch (LimitReachedException|AgentException $e) {
                        Toast::error(__('Aplikaci se nepodařilo nainstalovat'), $e->getMessage());
                    }
                }),
        ];
    }

    /** @return array<string, string> the engines an app may use (WordPress: MariaDB only), value => label */
    private function appEngines(?string $app): array
    {
        return collect(app(SystemInfo::class)->dbEngines())
            ->filter(fn ($e) => $app !== 'wordpress' || $e->value === 'mariadb')
            ->mapWithKeys(fn ($e) => [$e->value => $e->getLabel()])->all();
    }
}
