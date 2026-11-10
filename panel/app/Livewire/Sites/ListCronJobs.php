<?php

namespace App\Livewire\Sites;

use App\Actions\ManageCronJobs;
use App\Livewire\Concerns\TranslatesPageText;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Actions\Action;
use App\Wire\Resources\CronJobResource;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListCronJobs extends ListPage
{
    use TranslatesPageText;

    protected static ?string $resource = CronJobResource::class;

    protected ?string $title = 'Naplánované úlohy';

    protected function headerActions(): array
    {
        return [
            Action::make('create')->label(__('Nová úloha'))->icon('outline:plus')
                ->form(CronJobResource::fields())
                ->action(function (array $data) {
                    try {
                        app(ManageCronJobs::class)->create(Site::findOrFail($data['site_id']), $data);
                        Toast::success(__('Úloha naplánována'));
                    } catch (AgentException $e) {
                        Toast::error(__('Úlohu se nepodařilo naplánovat'), $e->getMessage());
                    }
                }),
        ];
    }
}
