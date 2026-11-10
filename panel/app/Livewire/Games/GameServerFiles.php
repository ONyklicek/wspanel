<?php

namespace App\Livewire\Games;

use App\Livewire\Concerns\WatchesAgentTasks;
use App\Models\GameServer;
use App\Wire\Resources\GameServerResource;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** A game server's files: its home, as its own user (a tab of the server). */
class GameServerFiles extends ViewPage
{
    use WatchesAgentTasks;

    protected static ?string $resource = GameServerResource::class;

    protected function agentTaskSubject(): ?Model
    {
        $server = $this->nativeRecord();

        return $server instanceof GameServer ? $server : null;
    }

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
    }

    public function getTitle(): ?string
    {
        $server = $this->nativeRecord();

        return $server instanceof GameServer ? $server->name : __('Herní server');
    }

    public function breadcrumbs(): array
    {
        $server = $this->nativeRecord();

        return [
            NavigationItem::make(__('Herní servery'))->url(GameServerResource::url('index')),
            NavigationItem::make((string) $this->getTitle())->url(GameServerResource::url('view', $server)),
            NavigationItem::make(__('Soubory')),
        ];
    }

    public function infolist(): Infolist
    {
        return Infolist::make()->schema([]);
    }

    public function render(): View
    {
        return view('livewire.games.files', [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => $this->subNavigation($this->nativeRecord()),
            'server' => $this->nativeRecord(),
        ]);
    }
}
