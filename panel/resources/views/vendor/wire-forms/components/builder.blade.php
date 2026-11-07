{{-- Builder: a repeater whose every item picks its own block type. Item chrome
     matches the repeater card; only the schema and the add trigger differ. --}}
@php
    use NyonCode\WireCore\Foundation\Enums\ItemExpansion;
    use NyonCode\WireForms\Components\Builder;

    assert($field instanceof Builder);

    $statePath = $field->getStatePath();
    $items = data_get($this, $statePath, []);
    if (!is_array($items)) $items = [];
    $itemCount = count($items);
    $blocks = $field->getBlocks();
@endphp

@include('wire-forms::partials.field-assets')

@if($field->isReorderable())
    @include('wire-core::partials.sortable-list-assets')
@endif

<div
    {{-- x-data must stay byte-identical across Livewire morphs — see the repeater
         view: keying collapse state by index keeps the attribute text static as
         items are added and removed, and the per-item default is passed per row
         rather than interpolated here. `adding` drives the block picker. --}}
    {{-- Body registered as `wireBuilderBlocks`; only per-instance config here. --}}
    x-data="wireBuilderBlocks({
        allCollapsed: @js($field->getItemExpansion() === ItemExpansion::None),
    })"
    class="space-y-2"
>
    @if($field->getLabel() || ($field->isCollapsible() && $itemCount > 1))
        <div class="flex items-center justify-between gap-2">
            @if($field->getLabel())
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ $field->getLabel() }}
                </label>
            @else
                <span></span>
            @endif

            @if($field->isCollapsible() && $itemCount > 1)
                <button
                    type="button"
                    @click="setAllCollapsed(!allCollapsed)"
                    data-testid="form-builder-{{ $statePath }}-toggle-all"
                    class="flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                >
                    <span x-show="!allCollapsed" class="flex items-center gap-1">
                        {!! icon('outline:chevron-double-up', 'w-3.5 h-3.5') !!}{{ __('Collapse all') }}
                    </span>
                    <span x-show="allCollapsed" x-cloak class="flex items-center gap-1">
                        {!! icon('outline:chevron-double-down', 'w-3.5 h-3.5') !!}{{ __('Expand all') }}
                    </span>
                </button>
            @endif
        </div>
    @endif

    <div
        class="space-y-2"
        @if($field->isReorderable())
            x-data="wireSortableList()"
            {{-- Livewire's own Alpine plugin builds the Sortable; this controller
                 hands it the whole option set. The library is in Livewire's
                 bundle already, so shipping a second copy was 39 kB of the same
                 code twice. See `wireSortableList`'s header. --}}
            x-sort
            x-sort:config="sortableConfig()"
            x-on:sorted="$wire.reorderRepeaterItems('{{ $statePath }}', $event.detail.order)"
        @endif
    >
    @foreach($items as $index => $item)
        @php
            $type = $field->getItemType($item);
            $block = $type !== null ? $field->getBlock($type) : null;
            $itemLabel = $field->getItemLabel(is_array($item) ? ($item['data'] ?? []) : [], $index);
            $startsCollapsed = $field->isItemCollapsedByDefault($index, $itemCount);
        @endphp

        <div
            data-collapsible-item="{{ $index }}"
            @if($field->isReorderable()) data-sortable-item="{{ $index }}" @endif
            wire:key="builder-{{ $statePath }}-{{ $index }}"
            class="rounded-lg border border-gray-200 bg-white dark:border-gray-600 dark:bg-gray-800"
        >
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-2 dark:border-gray-600">
                <div class="flex items-center gap-2">
                    @if($field->isReorderable())
                        <button type="button" data-sortable-handle data-testid="form-builder-{{ $statePath }}-reorder-{{ $index }}" aria-label="{{ __('Reorder') }}" class="cursor-grab text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                            {!! icon('outline:bars-3', 'w-4 h-4', 'w-4 h-4') !!}
                        </button>

                        {{-- The keyboard's half of reordering; see the repeater view. --}}
                        <span class="flex flex-col">
                            <button
                                type="button"
                                wire:click="moveRepeaterItem('{{ $statePath }}', {{ $index }}, {{ $index - 1 }})"
                                data-testid="form-builder-{{ $statePath }}-move-up-{{ $index }}"
                                aria-label="{{ __('Move up') }}"
                                @disabled($index === 0)
                                class="text-gray-500 hover:text-gray-600 disabled:opacity-30 disabled:hover:text-gray-400 dark:hover:text-gray-300"
                            >{!! icon('outline:chevron-up', 'w-3 h-3') !!}</button>
                            <button
                                type="button"
                                wire:click="moveRepeaterItem('{{ $statePath }}', {{ $index }}, {{ $index + 1 }})"
                                data-testid="form-builder-{{ $statePath }}-move-down-{{ $index }}"
                                aria-label="{{ __('Move down') }}"
                                @disabled($index === $itemCount - 1)
                                class="text-gray-500 hover:text-gray-600 disabled:opacity-30 disabled:hover:text-gray-400 dark:hover:text-gray-300"
                            >{!! icon('outline:chevron-down', 'w-3 h-3') !!}</button>
                        </span>
                    @endif

                    <span data-testid="form-builder-{{ $statePath }}-label-{{ $index }}" class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">
                        @if($block?->getIcon())
                            {!! icon($block->getIcon(), 'w-4 h-4', 'text-gray-400') !!}
                        @endif
                        {{-- An item whose block is gone still names its stored type, so
                             the content can be recognised and moved rather than lost. --}}
                        {{ $block?->getLabel() ?? $type ?? __('Unknown block') }}
                        @if($itemLabel !== null)
                            <span class="font-normal text-gray-500 dark:text-gray-400">{{ $itemLabel }}</span>
                        @endif
                    </span>
                </div>

                <div class="flex items-center gap-1">
                    @if($field->isCloneable())
                        <button
                            type="button"
                            wire:click="cloneRepeaterItem('{{ $statePath }}', {{ $index }}, '{{ $field->getItemKeyName() }}')"
                            data-testid="form-builder-{{ $statePath }}-clone-{{ $index }}"
                            aria-label="{{ __('Duplicate') }}"
                            class="p-1 text-gray-500 hover:text-gray-600 dark:hover:text-gray-300"
                        >
                            {!! icon('duplicate', 'w-4 h-4', 'w-4 h-4') !!}
                        </button>
                    @endif

                    @if($field->isCollapsible())
                        <button type="button" @click="toggleCollapse({{ $index }}, {{ $startsCollapsed ? 'true' : 'false' }})" data-testid="form-builder-{{ $statePath }}-collapse-{{ $index }}" class="p-1 text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                            {!! icon('chevron-down', 'w-4 h-4', 'w-4 h-4 transition-transform', '', [':class' => "{ 'rotate-180': !isCollapsed({$index}, ".($startsCollapsed ? 'true' : 'false').") }"]) !!}
                        </button>
                    @endif

                    @if($field->isDeletable() && ($field->getMinItems() === null || $itemCount > $field->getMinItems()))
                        <button
                            type="button"
                            wire:click="removeRepeaterItem('{{ $statePath }}', {{ $index }})"
                            data-testid="form-builder-{{ $statePath }}-remove-{{ $index }}"
                            aria-label="{{ __('Remove') }}"
                            class="p-1 text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                        >
                            {!! icon('trash', 'w-4 h-4', 'w-4 h-4') !!}
                        </button>
                    @endif
                </div>
            </div>

            <div x-show="!isCollapsed({{ $index }}, {{ $startsCollapsed ? 'true' : 'false' }})" x-collapse @if($startsCollapsed) x-cloak @endif class="space-y-4 p-4">
                @foreach($field->getItemSchema($index, $type ?? '') as $component)
                    @if($component->isVisible())
                        {{ $component }}
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach
    </div>

    @if($items === [])
        <p data-testid="form-builder-{{ $statePath }}-empty" class="rounded-lg border border-dashed border-gray-200 px-3 py-4 text-center text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
            {{ $field->getEmptyLabel() }}
        </p>
    @endif

    @if($field->isAddable() && ($field->getMaxItems() === null || $itemCount < $field->getMaxItems()))
        <div class="relative">
            <button
                type="button"
                @click="adding = !adding"
                data-testid="form-builder-{{ $statePath }}-add"
                class="flex w-full items-center justify-center gap-1 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm font-medium text-primary-600 transition-colors hover:border-primary-300 hover:text-primary-800 dark:border-gray-600 dark:text-primary-400 dark:hover:border-primary-500 dark:hover:text-primary-300"
            >
                {!! icon('plus', 'w-4 h-4', 'w-4 h-4') !!}
                {{ $field->getAddButtonLabel() }}
            </button>

            {{-- The picker is what makes this a builder rather than a repeater:
                 adding an item means choosing which block to add. --}}
            <div
                x-show="adding"
                x-cloak
                @click.outside="adding = false"
                class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-800"
            >
                @foreach($blocks as $block)
                    <button
                        type="button"
                        wire:click="addBuilderItem('{{ $statePath }}', '{{ $block->getName() }}')"
                        @click="adding = false"
                        data-testid="form-builder-{{ $statePath }}-add-{{ $block->getName() }}"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        @if($block->getIcon())
                            {!! icon($block->getIcon(), 'w-4 h-4', 'text-gray-400') !!}
                        @endif
                        {{ $block->getLabel() }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
