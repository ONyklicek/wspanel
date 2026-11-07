{{-- Repeater in its table layout: one column per schema field, headed once.
     Same state paths, add/remove/clone/reorder wiring and Livewire methods as the
     card layout — only the arrangement differs. --}}
@php
    use NyonCode\WireForms\Components\Repeater;

    assert($field instanceof Repeater);

    $statePath = $field->getStatePath();
    $items = data_get($this, $statePath, []);
    if (!is_array($items)) $items = [];
    $itemCount = count($items);
    $headings = $field->getTableHeadings();
    $canRemove = $field->isDeletable();
    $canClone = $field->isCloneable();
    $showLabels = $field->hasItemLabel();

    // Every column the header draws that is not a schema field, so the empty
    // row's colspan cannot drift from what is above it.
    $extraColumns = ($field->isReorderable() ? 1 : 0)
        + ($showLabels ? 1 : 0)
        + ($canClone ? 1 : 0)
        + ($canRemove ? 1 : 0);
@endphp

@if($field->isReorderable())
    @include('wire-core::partials.sortable-list-assets')
@endif

<div class="space-y-2">
    @if($field->getLabel())
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ $field->getLabel() }}
        </label>
    @endif

    {{-- `wire-scroller` for the reason wire-table's data region carries it: a
         repeater wider than its field clips in silence on macOS and iOS, whose
         overlay scrollbar fades a second after the last scroll — so a set of
         fields nobody has touched shows no sign that more of them are to the
         right. The rules are wire-core's, which is why this can use them at
         all: wire-forms sits below wire-table in the graph. --}}
    @include('wire-core::partials.scroller-assets')
    <div
        class="overflow-x-auto wire-scroller rounded-lg border border-gray-200 dark:border-gray-600"
        @if($field->isReorderable())
            {{-- The rows live in <tbody>, not among this element's own children,
                 so the scope sits here and the drag is bound down there. It used
                 to be a `container: 'tbody'` selector the controller resolved;
                 the directive goes straight on the element now, which is one
                 fewer thing that can point at the wrong node. --}}
            x-data="wireSortableList()"
            x-on:sorted="$wire.reorderRepeaterItems('{{ $statePath }}', $event.detail.order)"
        @endif
    >
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    @if($field->isReorderable())
                        <th scope="col" class="w-16 px-2 py-2"><span class="sr-only">{{ __('Reorder') }}</span></th>
                    @endif

                    @if($showLabels)
                        {{-- Numbering and the row's name, which the card layout puts
                             in each item's header. Only when `itemLabel()` was
                             configured: gating on whether a given row's closure
                             *resolves* would make the heading come and go. --}}
                        <th scope="col" class="w-px whitespace-nowrap px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Item') }}
                        </th>
                    @endif

                    @foreach($headings as $heading)
                        <th scope="col" class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $heading }}
                        </th>
                    @endforeach

                    @if($canClone)
                        <th scope="col" class="w-10 px-2 py-2"><span class="sr-only">{{ __('Duplicate') }}</span></th>
                    @endif

                    @if($canRemove)
                        <th scope="col" class="w-10 px-2 py-2"><span class="sr-only">{{ __('Remove') }}</span></th>
                    @endif
                </tr>
            </thead>

            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-600 dark:bg-gray-800"
                   @if($field->isReorderable()) x-sort x-sort:config="sortableConfig()" @endif>
                @foreach($items as $index => $item)
                    <tr @if($field->isReorderable()) data-sortable-item="{{ $index }}" @endif>
                        @if($field->isReorderable())
                            <td class="px-2 py-2 align-top">
                                <div class="flex items-center gap-1">
                                    <button type="button" data-sortable-handle data-testid="form-repeater-{{ $statePath }}-reorder-{{ $index }}" aria-label="{{ __('Reorder') }}" class="cursor-grab text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                                        {!! icon('outline:bars-3', 'w-4 h-4', 'w-4 h-4') !!}
                                    </button>

                                    {{-- The keyboard's half of reordering; see the card layout. --}}
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
                                </div>
                            </td>
                        @endif

                        @if($showLabels)
                            @php $itemLabel = $field->getItemLabel(is_array($item) ? $item : [], $index); @endphp
                            <td class="whitespace-nowrap px-3 py-2 align-top text-sm font-medium text-gray-700 dark:text-gray-300">
                                #{{ $index + 1 }}
                                @if($itemLabel !== null)
                                    <span class="ml-1 font-normal text-gray-500 dark:text-gray-400">{{ $itemLabel }}</span>
                                @endif
                            </td>
                        @endif

                        @foreach($field->getItemSchema($index) as $component)
                            @php
                                // The column header already names the field, so a
                                // per-cell label would repeat it on every row.
                                if (method_exists($component, 'hiddenLabel')) {
                                    $component->hiddenLabel();
                                }
                            @endphp
                            <td class="px-3 py-2 align-top">
                                @if($component->isVisible()){{ $component }}@endif
                            </td>
                        @endforeach

                        @if($canClone)
                            <td class="px-2 py-2 align-top">
                                <button
                                    type="button"
                                    wire:click="cloneRepeaterItem('{{ $statePath }}', {{ $index }}, '{{ $field->getItemKeyName() }}')"
                                    data-testid="form-repeater-{{ $statePath }}-clone-{{ $index }}"
                                    aria-label="{{ __('Duplicate') }}"
                                    class="p-1 text-gray-500 hover:text-gray-600 dark:hover:text-gray-300"
                                >
                                    {!! icon('duplicate', 'w-4 h-4', 'w-4 h-4') !!}
                                </button>
                            </td>
                        @endif

                        @if($canRemove)
                            <td class="px-2 py-2 align-top">
                                @if($field->getMinItems() === null || $itemCount > $field->getMinItems())
                                    <button
                                        type="button"
                                        wire:click="removeRepeaterItem('{{ $statePath }}', {{ $index }})"
                                        data-testid="form-repeater-{{ $statePath }}-remove-{{ $index }}"
                                        aria-label="{{ __('Remove') }}"
                                        class="p-1 text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                    >
                                        {!! icon('trash', 'w-4 h-4', 'w-4 h-4') !!}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach

                @if($items === [])
                    <tr>
                        <td colspan="{{ count($headings) + $extraColumns }}" data-testid="form-repeater-{{ $statePath }}-empty" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ $field->getEmptyLabel() }}
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    @if($field->isAddable() && ($field->getMaxItems() === null || $itemCount < $field->getMaxItems()))
        <button
            type="button"
            wire:click="addRepeaterItem('{{ $statePath }}')"
            data-testid="form-repeater-{{ $statePath }}-add"
            class="flex w-full items-center justify-center gap-1 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm font-medium text-primary-600 transition-colors hover:border-primary-300 hover:text-primary-800 dark:border-gray-600 dark:text-primary-400 dark:hover:border-primary-500 dark:hover:text-primary-300"
        >
            {!! icon('plus', 'w-4 h-4', 'w-4 h-4') !!}
            {{ $field->getAddButtonLabel() }}
        </button>
    @endif
</div>
