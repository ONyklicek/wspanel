{{-- A board: lanes side by side, cards dragged between them.

     The drag is Livewire's own `wire:sort` — one group across every lane, the
     lane's name as the group id — so a drop calls `moveBoardCard(key, position,
     lane)` on the host with nothing of this package's in the browser. Everything
     drawn here is resolved by WithBoard::boardLanesForView().

     Scrolls sideways on a narrow screen rather than squeezing a lane to a
     column of broken words.

     Variables: $lanes — array of [key, label, markerClasses, cards[key, title, description, url]]. --}}
<div data-testid="board" @wireEl('board') class="flex gap-4 overflow-x-auto pb-2">
    @foreach($lanes as $lane)
        <section
            wire:key="board-lane-{{ $lane['key'] }}"
            data-testid="board-lane-{{ $lane['key'] }}" @wireEl('board-lane')
            class="flex w-72 shrink-0 flex-col gap-3 rounded-xl bg-gray-50 p-3 dark:bg-gray-800/50"
        >
            <header class="flex items-center justify-between gap-2">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                    <span class="h-2 w-2 rounded-full {{ $lane['markerClasses'] }}"></span>
                    {{ $lane['label'] }}
                </h3>
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ count($lane['cards']) }}</span>
            </header>

            <ul
                wire:sort="moveBoardCard"
                wire:sort:group="wire-board"
                wire:sort:group-id="{{ $lane['key'] }}"
                class="flex min-h-16 flex-col gap-2"
            >
                @foreach($lane['cards'] as $card)
                    <li
                        wire:key="board-card-{{ $card['key'] }}"
                        wire:sort:item="{{ $card['key'] }}"
                        data-testid="board-card-{{ $card['key'] }}" @wireEl('board-card')
                        class="cursor-grab rounded-lg border border-gray-200 bg-white p-3 shadow-sm active:cursor-grabbing dark:border-gray-700 dark:bg-gray-900"
                    >
                        @if($card['url'])
                            <a href="{{ $card['url'] }}" @wireNavigate($card['url']) class="block text-sm font-medium text-gray-900 hover:underline dark:text-white">{{ $card['title'] }}</a>
                        @else
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $card['title'] }}</p>
                        @endif

                        @if($card['description'])
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $card['description'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
