<?php

namespace App\Filament\Resources\DnsZones\Pages;

use App\Filament\Resources\DnsZones\DnsZoneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDnsZones extends ListRecords
{
    protected static string $resource = DnsZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Přidat zónu')];
    }
}
