<?php

namespace App\Livewire\Clients;

use App\Models\Package;
use App\Support\Toast;
use App\Wire\Resources\PackageResource;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListPackages extends ListPage
{
    protected static ?string $resource = PackageResource::class;

    protected function headerActions(): array
    {
        return [
            Action::make('create')
                ->label('Nový balíček')
                ->icon('outline:plus')
                ->modalHeading('Nový balíček')
                ->modalWidth('4xl')
                ->form(PackageResource::formSchema())
                ->action(function (array $data) {
                    Package::create(PackageResource::normalize($data));
                    Toast::success('Balíček vytvořen');
                }),
        ];
    }
}
