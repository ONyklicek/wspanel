<?php

namespace App\Filament\Resources\Services\Pages;

use App\Actions\Services\SyncServices;
use App\Filament\Resources\Services\ServiceResource;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;
use App\Services\FrankenPhpRelease;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

class ListServices extends ListRecords
{
    protected static string $resource = ServiceResource::class;

    protected ?string $subheading = 'Skutečný stav ze systemd. Panel ho ověřuje každou minutu.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('updateFrankenphp')
                ->label(fn () => 'Aktualizovat FrankenPHP na v'.app(FrankenPhpRelease::class)->latest())
                ->icon(Heroicon::OutlinedArrowUpCircle)
                ->color('warning')
                ->visible(fn () => app(FrankenPhpRelease::class)->updateAvailable())
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Stáhne v'.app(FrankenPhpRelease::class)->latest().' z GitHubu, ověří ji otiskem sha256 a zkontroluje s ní všechny konfigurace. Pak restartuje proxy, web tier a worker weby: všechny weby budou pár sekund nedostupné. Když něco nenaběhne, vrátí se původní binárka.')
                ->action(function () {
                    $version = 'v'.app(FrankenPhpRelease::class)->latest();
                    try {
                        $job = app(AgentClient::class)->call('frankenphp.update', ['version' => $version]);
                        $done = app(AgentJobs::class)->await($job['job_id'], 90) !== null;
                        Cache::forget('agent.sys.versions');
                        Notification::make()
                            ->title($done ? "FrankenPHP aktualizován na {$version}" : 'Aktualizace běží na pozadí')
                            ->success()->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Aktualizace FrankenPHP selhala')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('sync')
                ->label('Ověřit stav')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function () {
                    try {
                        $n = app(SyncServices::class)->handle();
                        Notification::make()->title("Stav {$n} služeb ověřen")->success()->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Agent není dostupný')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
