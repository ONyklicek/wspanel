<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\InstallApp;
use App\Exceptions\LimitReachedException;
use App\Livewire\Concerns\TranslatesPageText;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Lang;
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
}
