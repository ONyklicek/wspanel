<?php

namespace App\Livewire\Databases;

use App\Actions\Databases\SyncDatabases;
use App\Enums\DatabaseEngine;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;
use App\Services\Agent\SystemInfo;
use App\Support\Toast;
use App\Wire\Forms\Options;
use App\Wire\Resources\DatabaseResource;
use Illuminate\Support\Facades\Cache;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Select;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListDatabases extends ListPage
{
    protected static ?string $resource = DatabaseResource::class;

    public function mount(): void
    {
        rescue(fn () => app(SyncDatabases::class)->handle(), report: false);
    }

    protected function headerActions(): array
    {
        $missing = collect(DatabaseEngine::cases())
            ->reject(fn (DatabaseEngine $e) => in_array($e, app(SystemInfo::class)->dbEngines(), true))
            ->mapWithKeys(fn (DatabaseEngine $e) => [$e->value => $e->getLabel()]);

        return [
            DatabaseResource::createAction(),
            Action::make('installEngine')
                ->label('Doinstalovat engine')
                ->icon('outline:plus-circle')
                ->color('gray')
                ->visible($missing->isNotEmpty())
                ->modalDescription('Nainstaluje databázový server a PHP ovladače pro všechny verze PHP. Engine pak z panelu nejde odebrat (odinstalace by smazala data).')
                ->form([Select::make('engine')->rules([Options::rule()])->label('Engine')->options($missing->all())->required()])
                ->action(function (array $data) {
                    try {
                        $job = app(AgentClient::class)->call('engine.install', ['engine' => $data['engine']]);
                        $done = app(AgentJobs::class)->await($job['job_id'], 25) !== null;
                        Cache::forget('agent.sys.versions');
                        Toast::success($done ? 'Engine nainstalován' : 'Instalace běží na pozadí', $done ? null : 'Obnovte stránku za pár minut.');
                    } catch (AgentException $e) {
                        Toast::error('Instalace selhala', $e->getMessage());
                    }
                }),
        ];
    }
}
