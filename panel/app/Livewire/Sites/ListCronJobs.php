<?php

namespace App\Livewire\Sites;

use App\Actions\ManageCronJobs;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Resources\CronJobResource;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListCronJobs extends ListPage
{
    protected static ?string $resource = CronJobResource::class;

    protected ?string $title = 'Naplánované úlohy';

    protected function headerActions(): array
    {
        return [
            Action::make('create')->label('Nová úloha')->icon('outline:plus')
                ->form(CronJobResource::fields())
                ->action(function (array $data) {
                    try {
                        app(ManageCronJobs::class)->create(Site::findOrFail($data['site_id']), $data);
                        Toast::success('Úloha naplánována');
                    } catch (AgentException $e) {
                        Toast::error('Úlohu se nepodařilo naplánovat', $e->getMessage());
                    }
                }),
        ];
    }
}
