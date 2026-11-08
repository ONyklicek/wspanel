<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\InstallApp;
use App\Exceptions\LimitReachedException;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Toast;
use App\Wire\Forms\Options;
use App\Wire\Resources\SiteResource;
use App\Wire\Sites\SiteFields;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\Select;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListSites extends ListPage
{
    protected static ?string $resource = SiteResource::class;

    protected function headerActions(): array
    {
        return [
            $this->createHeaderAction()?->label('Nový web'),
            Action::make('installApp')->label('Nová aplikace')->icon('outline:sparkles')->color('gray')
                ->modalHeading('Web s aplikací jedním klikem')
                ->modalDescription('Založí web, jeho databázi a aplikaci. Laravel a Symfony se nasadí z oficiální kostry jako běžný deploy, takže aktualizace a návrat zpět fungují jako u každého webu.')
                ->form([
                    Radio::make('app')->label('Aplikace')->options(InstallApp::APPS)->default('wordpress')->required()->rules([Options::rule(InstallApp::APPS)]),
                    SiteFields::domain(),
                    Select::make('php_version')->label('PHP')->options(fn () => app(SystemInfo::class)->phpVersionOptions())
                        ->default(fn () => app(SystemInfo::class)->defaultPhpVersion())->rules([Options::rule()])->required(),
                ])
                ->action(function (array $data) {
                    try {
                        $out = app(InstallApp::class)->handle($data);
                        Toast::success(InstallApp::APPS[$data['app']].' na '.$out['site']->domain, $out['note']);
                        $this->redirect(SiteResource::url('view', $out['site']));
                    } catch (LimitReachedException|AgentException $e) {
                        Toast::error('Aplikaci se nepodařilo nainstalovat', $e->getMessage());
                    }
                }),
        ];
    }
}
