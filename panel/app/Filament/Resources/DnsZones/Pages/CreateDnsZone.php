<?php

namespace App\Filament\Resources\DnsZones\Pages;

use App\Actions\Dns\ManageDnsZones;
use App\Filament\Resources\DnsZones\DnsZoneResource;
use App\Services\Dns\DnsException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateDnsZone extends CreateRecord
{
    protected static string $resource = DnsZoneResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ManageDnsZones::class)->create($data);
        } catch (DnsException $e) {
            Notification::make()->title('Zónu se nepodařilo přidat')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
