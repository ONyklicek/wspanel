<?php

namespace App\Livewire\Clients;

use App\Livewire\Concerns\TranslatesPageText;
use App\Models\Package;
use App\Support\Toast;
use App\Wire\Actions\Action;
use App\Wire\Resources\PackageResource;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListPackages extends ListPage
{
    use TranslatesPageText;

    protected static ?string $resource = PackageResource::class;

    protected function headerActions(): array
    {
        return [
            Action::make('create')
                ->label(__('Nový balíček'))
                ->icon('outline:plus')
                ->modalHeading(__('Nový balíček'))
                ->modalWidth('4xl')
                ->form(PackageResource::formSchema())
                ->action(function (array $data) {
                    Package::create(PackageResource::normalize($data));
                    Toast::success(__('Balíček vytvořen'));
                }),
        ];
    }
}
