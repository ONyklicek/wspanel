@php
    use NyonCode\WireCore\Foundation\Enums\ItemExpansion;
    use NyonCode\WireForms\Components\Repeater;

    assert($field instanceof Repeater);

    $statePath = $field->getStatePath();
    $items = data_get($this, $statePath, []);
    if (!is_array($items)) $items = [];
    $itemCount = count($items);
    $canRemove = $field->isDeletable() && ($field->getMinItems() === null || $itemCount > $field->getMinItems());
    $canAdd = $field->isAddable() && ($field->getMaxItems() === null || $itemCount < $field->getMaxItems());
@endphp

@include('wire-forms::partials.field-assets')

@if($field->isReorderable())
    {{-- Only a reorderable repeater pays for SortableJS; the partial dedupes to
         one tag however many repeaters ask for it. --}}
    @include('wire-core::partials.sortable-list-assets')
@endif

<div
    {{-- x-data must stay byte-identical across Livewire morphs: baking a
         per-item collapsed array (length = item count) meant adding/removing a
         row changed the attribute text, so Alpine re-initialised and reset every
         row's collapse state. Key collapse state by index in an object instead.
         The per-item *default* is passed per row (see below) for the same
         reason — `expandLast()` depends on the count, which must not appear here. --}}
    {{-- Body registered as `wireCollapsibleItems`; only per-instance config here. --}}
    x-data="wireCollapsibleItems({
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

            {{-- One toggle rather than two buttons: with a single row there is
                 nothing to do "to all", so it appears from two rows up. --}}
            @if($field->isCollapsible() && $itemCount > 1)
                <button
                    type="button"
                    @click="setAllCollapsed(!allCollapsed)"
                    data-testid="form-repeater-{{ $statePath }}-toggle-all"
                    class="flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                >
                    {{-- Two spans rather than one `x-text`: the label is translated,
                         and a translation carrying an apostrophe or a quote would
                         have to survive being interpolated into a JS expression
                         inside an HTML attribute. Blade already escapes text
                         nodes correctly, so let it. --}}
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
            {{-- A second, nested scope rather than one merged object: folding and
                 dragging are different surfaces with different owners (this one
                 lives in wire-core, where a non-forms list can reach it), and an
                 Alpine child scope still sees `isCollapsed` from the parent. --}}
            x-data="wireSortableList()"
            {{-- See the builder's copy of these two lines: Livewire's plugin owns
                 the Sortable instance, this controller owns how it behaves. --}}
            x-sort
            x-sort:config="sortableConfig()"
            x-on:sorted="$wire.reorderRepeaterItems('{{ $statePath }}', $event.detail.order)"
        @endif
    >
        @foreach($items as $index => $item)
            @php
                $itemLabel = $field->getItemLabel(is_array($item) ? $item : [], $index);
                $startsCollapsed = $field->isItemCollapsedByDefault($index, $itemCount);
            @endphp

            <div
                data-collapsible-item="{{ $index }}"
                @if($field->isReorderable()) data-sortable-item="{{ $index }}" @endif
                @class([
                    'border border-gray-200 dark:border-gray-600 rounded-lg',
                    'bg-white dark:bg-gray-800',
                ])
            >
                <div class="flex items-center justify-between px-4 py-2 border-b border-gray-200 dark:border-gray-600">
                    <div class="flex items-center gap-2">
                        @if($field->isReorderable())
                            <button type="button" data-sortable-handle data-testid="form-repeater-{{ $statePath }}-reorder-{{ $index }}" aria-label="{{ __('Reorder') }}" class="cursor-grab text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                                {!! icon('outline:bars-3', 'w-4 h-4', 'w-4 h-4') !!}
                            </button>

                            {{-- The keyboard's half of reordering. A drag handle is
                                 unusable without a pointer, so these are not a
                                 convenience — they are what makes `reorderable()`
                                 operable at all for anyone tabbing through the form. --}}
                            <span class="flex flex-col">
                                <button
                                    type="button"
                                    wire:click="moveRepeaterItem('{{ $statePath }}', {{ $index }}, {{ $index - 1 }})"
                                    data-testid="form-repeater-{{ $statePath }}-move-up-{{ $index }}"
                                    aria-label="{{ __('Move up') }}"
                                    @disabled($index === 0)
                                    class="text-gray-500 hover:text-gray-600 disabled:opacity-30 disabled:hover:text-gray-400 dark:hover:text-gray-300"
                                >{!! icon('outline:chevron-up', 'w-3 h-3') !!}</button>
                                <button
                                    type="button"
                                    wire:click="moveRepeaterItem('{{ $statePath }}', {{ $index }}, {{ $index + 1 }})"
                                    data-testid="form-repeater-{{ $statePath }}-move-down-{{ $index }}"
                                    aria-label="{{ __('Move down') }}"
                                    @disabled($index === $itemCount - 1)
                                    class="text-gray-500 hover:text-gray-600 disabled:opacity-30 disabled:hover:text-gray-400 dark:hover:text-gray-300"
                                >{!! icon('outline:chevron-down', 'w-3 h-3') !!}</button>
                            </span>
                        @endif

                        <span data-testid="form-repeater-{{ $statePath }}-label-{{ $index }}" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            #{{ $index + 1 }}
                            @if($itemLabel !== null)
                                <span class="ml-1 font-normal text-gray-500 dark:text-gray-400">{{ $itemLabel }}</span>
                            @endif
                        </span>
                    </div>

                    <div class="flex items-center gap-1">
                        @if($field->isCloneable())
                            <button
                                type="button"
                                wire:click="cloneRepeaterItem('{{ $statePath }}', {{ $index }}, '{{ $field->getItemKeyName() }}')"
                                data-testid="form-repeater-{{ $statePath }}-clone-{{ $index }}"
                                aria-label="{{ __('Duplicate') }}"
                                class="p-1 text-gray-500 hover:text-gray-600 dark:hover:text-gray-300"
                            >
                                {!! icon('duplicate', 'w-4 h-4', 'w-4 h-4') !!}
                            </button>
                        @endif

                        @if($field->isCollapsible())
                            <button
                                type="button"
                                @click="toggleCollapse({{ $index }}, {{ $startsCollapsed ? 'true' : 'false' }})"
                                data-testid="form-repeater-{{ $statePath }}-collapse-{{ $index }}"
                                class="p-1 text-gray-500 hover:text-gray-600 dark:hover:text-gray-300"
                            >
                                {!! icon('chevron-down', 'w-4 h-4', 'w-4 h-4 transition-transform', '', [':class' => "{ 'rotate-180': !isCollapsed({$index}, ".($startsCollapsed ? 'true' : 'false').") }"]) !!}
                            </button>
                        @endif

                        @if($canRemove)
                            <button
                                type="button"
                                wire:click="removeRepeaterItem('{{ $statePath }}', {{ $index }})" data-testid="form-repeater-{{ $statePath }}-remove-{{ $index }}"
                                aria-label="{{ __('Remove') }}"
                                class="p-1 text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                            >
                                {!! icon('trash', 'w-4 h-4', 'w-4 h-4') !!}
                            </button>
                        @endif
                    </div>
                </div>

                <div
                    x-show="!isCollapsed({{ $index }}, {{ $startsCollapsed ? 'true' : 'false' }})"
                    x-collapse
                    @if($startsCollapsed) x-cloak @endif
                    class="p-4 space-y-4"
                >
                    @foreach($field->getItemSchema($index) as $component)
                        @if($component->isVisible())
                            {{ $component }}
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    @if($items === [])
        {{-- The table layout has said this since it shipped; a card repeater
             rendered an add button over blank space and left the user to infer
             the list was empty rather than broken. --}}
        <p data-testid="form-repeater-{{ $statePath }}-empty" class="rounded-lg border border-dashed border-gray-200 px-3 py-4 text-center text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
            {{ $field->getEmptyLabel() }}
        </p>
    @endif

    @if($canAdd)
        <button
            type="button"
            wire:click="addRepeaterItem('{{ $statePath }}')" data-testid="form-repeater-{{ $statePath }}-add"
            class="flex items-center gap-1 px-3 py-2 text-sm font-medium text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg w-full justify-center hover:border-primary-300 dark:hover:border-primary-500 transition-colors"
        >
            {!! icon('plus', 'w-4 h-4', 'w-4 h-4') !!}
            {{ $field->getAddButtonLabel() }}
        </button>
    @endif
</div>
