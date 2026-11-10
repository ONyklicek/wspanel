<?php

namespace App\Livewire\Games\Concerns;

use App\Livewire\Concerns\WatchesAgentTasks;
use App\Models\GameServer;
use App\Wire\Resources\GameServerResource;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Infolists\Infolist;

/** A tab of a game server: headed by its name, trail Herní servery › name › tab. */
trait IsGameTab
{
    use WatchesAgentTasks;

    abstract protected function tabLabel(): string;

    protected function agentTaskSubject(): ?Model
    {
        return $this->server();
    }

    public function server(): GameServer
    {
        /** @var GameServer */
        return $this->nativeRecord();
    }

    public function getTitle(): ?string
    {
        $server = $this->nativeRecord();

        return $server instanceof GameServer ? $server->name : __('Herní server');
    }

    public function breadcrumbs(): array
    {
        return [
            NavigationItem::make(__('Herní servery'))->url(GameServerResource::url('index')),
            NavigationItem::make((string) $this->getTitle())->url(GameServerResource::url('view', $this->nativeRecord())),
            NavigationItem::make($this->tabLabel()),
        ];
    }

    public function infolist(): Infolist
    {
        return Infolist::make()->schema([]);
    }

    /** @param  array<string, mixed>  $data */
    protected function tabView(string $view, array $data = []): View
    {
        return view($view, [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => $this->subNavigation($this->nativeRecord()),
            'server' => $this->server(),
        ] + $data);
    }
}
