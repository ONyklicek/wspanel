<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Actions\Sites\CreateSite as CreateSiteAction;
use App\Enums\SiteStatus;
use App\Filament\Resources\Sites\SiteResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateSiteAction::class)->handle($data);
    }

    protected function getCreatedNotification(): ?Notification
    {
        $site = $this->getRecord();

        if ($site->status === SiteStatus::Failed) {
            return Notification::make()
                ->title("Web {$site->domain} se nepodařilo založit")
                ->body($site->last_error)
                ->danger()
                ->persistent();
        }

        return Notification::make()
            ->title("Web {$site->domain} založen")
            ->body("{$site->runtimeLabel()}, uživatel {$site->system_user}.")
            ->success();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
