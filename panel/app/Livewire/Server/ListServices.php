<?php

namespace App\Livewire\Server;

use App\Actions\Services\SyncServices;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;
use App\Services\FrankenPhpRelease;
use App\Support\Toast;
use App\Wire\Resources\ServiceResource;
use Illuminate\Support\Facades\Cache;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListServices extends ListPage
{
    protected static ?string $resource = ServiceResource::class;

    protected ?string $title = 'Služby';

    protected function headerActions(): array
    {
        $franken = app(FrankenPhpRelease::class);

        return [
            Action::make('updateFrankenphp')
                ->label('Aktualizovat FrankenPHP na v'.$franken->latest())
                ->icon('outline:arrow-up-circle')
                ->color('warning')
                ->visible($franken->updateAvailable())
                ->requiresConfirmation()
                ->modalDescription('Stáhne novou verzi z GitHubu, ověří otisk sha256 a zkontroluje s ní všechny konfigurace. Pak restartuje proxy, web tier a worker weby (pár sekund výpadek). Když něco nenaběhne, vrátí se původní binárka.')
                ->action(function () use ($franken) {
                    $version = 'v'.$franken->latest();
                    try {
                        $job = app(AgentClient::class)->call('frankenphp.update', ['version' => $version]);
                        $done = app(AgentJobs::class)->await($job['job_id'], 90) !== null;
                        Cache::forget('agent.sys.versions');
                        Toast::success($done ? "FrankenPHP aktualizován na {$version}" : 'Aktualizace běží na pozadí');
                    } catch (AgentException $e) {
                        Toast::error('Aktualizace FrankenPHP selhala', $e->getMessage());
                    }
                }),
            Action::make('sync')
                ->label('Ověřit stav')
                ->icon('outline:arrow-path')
                ->color('gray')
                ->action(function () {
                    try {
                        Toast::success('Stav '.app(SyncServices::class)->handle().' služeb ověřen');
                    } catch (AgentException $e) {
                        Toast::error('Agent není dostupný', $e->getMessage());
                    }
                }),
        ];
    }
}
