<?php

namespace App\Livewire\Games;

use App\Actions\Games\ManageGameServers;
use App\Livewire\Concerns\TranslatesPageText;
use App\Services\Agent\AgentException;
use App\Support\Limits;
use App\Support\Toast;
use App\Wire\Actions\Action;
use App\Wire\Resources\GameServerResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use NyonCode\WireForms\Components\Display\Placeholder;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListGameServers extends ListPage
{
    use TranslatesPageText;

    protected static ?string $resource = GameServerResource::class;

    protected ?string $title = 'Herní servery';

    protected function headerActions(): array
    {
        return [
            Action::make('create')->label(__('Nový server'))->icon('outline:plus')
                ->modalHeading(__('Nový herní server'))->modalSubmitActionLabel(__('Vytvořit'))
                ->visible(fn () => app(Limits::class)->canAdd(Limits::forCurrentUser(), 'games'))
                ->modalDescription(__('Server se stáhne od vydavatele hry (s kontrolou otisku) a spustí; Minecraft si napoprvé vygeneruje svět, to trvá minutu až dvě.'))
                ->form(fn () => GameServerResource::createFields())
                ->action(function (array $data) {
                    try {
                        // Installs in the background: the server's page shows how it goes.
                        $server = app(ManageGameServers::class)->create($data);
                        $this->redirect(GameServerResource::url('view', $server));
                    } catch (AgentException $e) {
                        Toast::error(__('Server se nepodařilo vytvořit'), $e->getMessage());
                    }
                }),
            $this->resourcesAction(),
        ];
    }

    /**
     * The administrator's say in how games share the server with the sites:
     * the RAM the sites keep (the rest is the games' budget, any one server
     * may have all of it while it runs alone), whose CPU and disk come first,
     * and a cap on the CPU all games together take.
     */
    private function resourcesAction(): Action
    {
        $gb = fn (int $mb): string => Number::format($mb / 1024, maxPrecision: 1, locale: app()->getLocale()).' GB';

        return Action::make('resources')->label(__('Zdroje'))->icon('outline:adjustments-horizontal')->color('gray')
            ->visible(fn () => Gate::allows('game-packages'))
            ->modalHeading(__('Zdroje herních serverů'))->modalSubmitActionLabel(__('Uložit'))
            ->modalDescription(__('Weby, databáze a panel mají přednost; herní servery dostanou, co zbude. Jeden server smí mít celý rozpočet, spustí se ale jen tehdy, když se vejde vedle těch, které už běží.'))
            ->fillFormUsing(function () {
                if (! Gate::allows('game-packages')) {
                    return [];
                }
                try {
                    $r = app(ManageGameServers::class)->resources();
                } catch (AgentException) {
                    return [];
                }

                return [
                    'reserve_mode' => $r['reserve_mb'] > 0 ? 'custom' : 'auto',
                    'reserve_gb' => round(($r['reserve_mb'] ?: $r['auto_reserve_mb']) / 1024, 1),
                    'priority' => $r['priority'], 'cpu_cores' => $r['cpu_cores'],
                ];
            })
            ->form(function () use ($gb) {
                if (! Gate::allows('game-packages')) {
                    return [];
                }
                try {
                    $r = app(ManageGameServers::class)->resources();
                } catch (AgentException $e) {
                    return [Placeholder::make('error')->hiddenLabel()->content($e->getMessage())];
                }
                $running = $r['running'] === [] ? __('žádný') : collect($r['running'])
                    ->map(fn ($g) => e($g['name']).' ('.$gb((int) $g['unit_mb']).')')->implode(', ');

                return [
                    Placeholder::make('state')->hiddenLabel()->html(
                        '<dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">'
                        .'<dt class="text-gray-500">'.e(__('RAM serveru')).'</dt><dd>'.e($gb($r['ram_mb'])).'</dd>'
                        .'<dt class="text-gray-500">'.e(__('Pro herní servery')).'</dt><dd>'.e($gb($r['budget_mb'])).'</dd>'
                        .'<dt class="text-gray-500">'.e(__('Teď zabírají')).'</dt><dd>'.e($gb($r['running_mb'])).'</dd>'
                        .'<dt class="text-gray-500">'.e(__('Běží')).'</dt><dd>'.$running.'</dd></dl>'
                    ),
                    Select::make('reserve_mode')->label(__('Rezerva RAM pro weby'))->required()->live()
                        ->options([
                            'auto' => __('Automaticky (:gb: čtvrtina RAM, aspoň 1,5 GB)', ['gb' => $gb($r['auto_reserve_mb'])]),
                            'custom' => __('Vlastní'),
                        ]),
                    TextInput::make('reserve_gb')->label(__('Rezerva (GB)'))->numeric()->step(0.5)->minValue(1)
                        ->maxValue(max(1, round(($r['ram_mb'] - 512) / 1024, 1)))
                        ->visibleWhen('reserve_mode', 'custom')->requiredIf('reserve_mode', 'custom')
                        ->helperText(__('Kolik RAM herní servery nikdy nedostanou; zbytek je jejich rozpočet.')),
                    Radio::make('priority')->label(__('Přednost u procesoru a disku'))->required()->cards()
                        ->options([
                            'sites' => __('Weby mají přednost'),
                            'balanced' => __('Vyrovnané'),
                            'games' => __('Hry mají přednost'),
                        ])
                        ->descriptions([
                            'sites' => __('Herní servery dostanou výkon, který weby zrovna nepotřebují (doporučeno).'),
                            'balanced' => __('Herní servery a weby se dělí rovným dílem.'),
                            'games' => __('Herní servery dostanou výkon dřív než weby.'),
                        ]),
                    TextInput::make('cpu_cores')->label(__('Strop CPU pro všechny hry (jádra)'))->integer()->minValue(0)->maxValue($r['cpus'])->required()
                        ->helperText(__('0 = bez stropu. Server má :n jader; hry dohromady nepoužijí víc ani na jinak volném serveru.', ['n' => $r['cpus']])),
                ];
            })
            ->action(function (array $data) {
                Gate::authorize('game-packages');
                try {
                    app(ManageGameServers::class)->setResources([
                        'reserve_mb' => ($data['reserve_mode'] ?? 'auto') === 'custom' ? (int) round((float) $data['reserve_gb'] * 1024) : 0,
                        'priority' => (string) $data['priority'], 'cpu_cores' => (int) $data['cpu_cores'],
                    ]);
                    Toast::success(__('Zdroje herních serverů uloženy'));
                } catch (AgentException $e) {
                    Toast::error(__('Zdroje se nepodařilo uložit'), $e->getMessage());
                }
            });
    }
}
