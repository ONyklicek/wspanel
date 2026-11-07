@php
    use NyonCode\WireCore\Foundation\Schema\Section;
    use NyonCode\WireCore\Foundation\Support\ResponsiveGrid;

    assert($layout instanceof Section);

    // Int and per-breakpoint map alike resolve through the canonical owner —
    // see the note in schema/grid.blade.php for what the local `match` did.
    $columns = $layout->getColumns();
    $columnsClass = ResponsiveGrid::cols($columns);
    $isCollapsible = $layout->isCollapsible();
    $isCollapsed = $layout->isCollapsed();
    $headerActions = $layout->getHeaderActions();
    // aside(): heading beside the fields instead of above them. Implemented as a
    // grid around the two blocks that already exist, so collapsing, the column
    // grid and the header actions all keep working untouched.
    $isAside = $layout->isAside();
@endphp

<div
        @if($isCollapsible) x-data="{ open: {{ $isCollapsed ? 'false' : 'true' }} }" @endif
@class([
    'bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700',
    $layout->isCompact() ? 'p-3' : 'p-4 sm:p-6',
    'md:grid md:grid-cols-3 md:gap-6' => $isAside,
])
>
    @if($layout->getLabel() || $layout->getDescription() || $headerActions !== [])
        <div @class([
            // Stacked on a phone, side by side from `sm`: a heading, a
            // description and two header actions do not share 360px, and the
            // actions were the half that got squeezed to nothing.
            'flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between',
            // Beside the fields the heading needs no bottom margin — the grid gap
            // already separates them on md+, and it still stacks below that.
            'mb-4' => (! $isCollapsible || ! $isCollapsed) && ! $isAside,
            'mb-4 md:mb-0' => $isAside,
            'cursor-pointer' => $isCollapsible,
        ]) @if($isCollapsible) @click="open = !open" data-testid="section-toggle" @wireEl('section-toggle') role="button" :aria-expanded="open" tabindex="0" @endif>
            <div>
                @if($layout->getLabel())
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                        @if($layout->getIcon())
                            {!! icon($layout->getIcon(), 'w-4 h-4', 'w-5 h-5 text-gray-400') !!}
                        @endif
                        {{ $layout->getLabel() }}
                    </h3>
                @endif
                @if($layout->getDescription())
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ $layout->getDescription() }}
                    </p>
                @endif
            </div>

            {{-- Only where there is something to put in it: stacked on a
                 phone, an empty div is a 12px gap under the heading. --}}
            @if($headerActions !== [] || $isCollapsible)
                <div class="flex items-center gap-2 sm:ml-4 sm:shrink-0">
                    @foreach($headerActions as $headerAction)
                        <div @click.stop>
                            @include('wire-core::partials.component-action', ['action' => $headerAction])
                        </div>
                    @endforeach

                    @if($isCollapsible)
                        <button type="button" class="text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                            {!! icon('outline:chevron-down', 'w-4 h-4', 'w-5 h-5 transition-transform', '', ['x-bind:class' => "{ 'rotate-180': open }"]) !!}
                        </button>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <div
            @if($isCollapsible) x-show="open" x-collapse @endif
            @class([
                'grid gap-4',
                'md:col-span-2' => $isAside,
                $columnsClass,
            ])
    >
        @foreach($layout->getSchema() as $component)
            @if($component->isVisible())
                {{-- Told which grid it is in; see HasColumnSpan::inGridOf(). --}}
                {{ $component->inGridOf($columns) }}
            @endif
        @endforeach
    </div>
</div>
