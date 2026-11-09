<?php

namespace App\Livewire\Databases;

use App\Actions\Databases\SyncDatabases;
use App\Livewire\Concerns\TranslatesPageText;
use App\Wire\Resources\DatabaseResource;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListDatabases extends ListPage
{
    use TranslatesPageText;

    protected static ?string $resource = DatabaseResource::class;

    public function mount(): void
    {
        rescue(fn () => app(SyncDatabases::class)->handle(), report: false);
    }

    protected function headerActions(): array
    {
        return [
            DatabaseResource::createAction(),
        ];
    }
}
