@php /** @var \NyonCode\WireForms\Components\CheckboxList $field */
    $wireModifier = $field->getWireModelModifier();
    $wireAttr = 'wire:model' . ($wireModifier ? ".{$wireModifier}" : '');
    $options = $field->getOptions();
    $columns = $field->getColumns();
    // Per-breakpoint map (['md' => 2, 'lg' => 3]) → literal grid-cols classes;
    // a plain int keeps the mobile-first reflow arms below.
    $columnsClass = is_array($columns) ? \NyonCode\WireCore\Foundation\Support\ResponsiveGrid::cols($columns) : '';
    // groups(['Fruit' => ['apple' => 'Apple'], …]) renders a heading per group.
    // Without an explicit map, grouped() alone has nothing to group by, so the
    // list stays flat rather than inventing a grouping.
    $groups = $field->isGrouped() ? $field->getGroups() : [];
    // @entangle takes no wire:model modifiers — see CanBeLive::getEntangleModifier().
    $entangleModifier = $field->getEntangleModifier();
@endphp

@include('wire-forms::partials.field-assets')

@include('wire-forms::partials.field-wrapper-start')

@if($field->isSegmented() || $field->isButtons())
    @include('wire-forms::partials.checkbox-list-choices', ['field' => $field, 'wireAttr' => $wireAttr, 'options' => $options])
@else
    <div
        {{-- Body registered as `wireCheckboxList`; only per-instance config here. --}}
        x-data="wireCheckboxList({
            statePath: @js($field->getWireModelAttribute()),
            values: @js(array_keys($options)),
            labels: @js($options),
            {{-- Entangled only where the chips need it. `@entangle` compiles to
                 a `$__livewire` lookup, so emitting it unconditionally would
                 make every checkbox list — including the ones with no chips —
                 renderable only inside a Livewire component. --}}
            @if($field->isShowingSelected())
                state: @entangle($field->getWireModelAttribute()){{ $entangleModifier ? '.'.$entangleModifier : '' }},
            @endif
        })"
        class="border border-gray-300 dark:border-gray-600 rounded-md overflow-hidden"
    >
        @if($field->isShowingSelected())
            {{-- What is chosen, above the list that hides it. A long list only
                 shows the rows near the scroll position and a searched one only
                 the matches, so "what have I actually picked" is otherwise off
                 screen — which is the one thing a multi-select did better.
                 Absent entirely while nothing is selected: an empty bar is a
                 row of chrome that never says anything. --}}
            <div
                x-show="selected.length"
                x-cloak
                class="flex flex-wrap gap-1.5 border-b border-gray-200 bg-gray-50 p-2 dark:border-gray-700 dark:bg-gray-800"
                data-testid="form-checklist-{{ $field->getStatePath() }}-selected"
            >
                <template x-for="chosen in selected" :key="chosen.value">
                    <span class="inline-flex items-center gap-1 rounded-full border border-gray-300 bg-white py-0.5 ps-2.5 pe-1 text-xs font-medium text-gray-800 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <span x-text="chosen.label" class="leading-none"></span>

                        @unless($field->isDisabled())
                            <button
                                type="button"
                                x-on:click="remove(chosen.value)"
                                :data-testid="'form-checklist-{{ $field->getStatePath() }}-unpick-' + chosen.value"
                                :aria-label="'{{ __('wire-forms::fields.deselect') }} ' + chosen.label"
                                class="flex h-4 w-4 items-center justify-center rounded-full text-gray-500 transition-colors hover:bg-gray-200 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-gray-600 dark:hover:text-gray-100"
                            >
                                {!! icon('outline:x-mark', 'h-3 w-3', 'h-3 w-3') !!}
                            </button>
                        @endunless
                    </span>
                </template>
            </div>
        @endif

        @if($field->isSearchable())
            <div class="p-2 border-b border-gray-200 dark:border-gray-700">
                <input
                    type="text"
                    autocomplete="off"
                    x-model="search"
                    data-testid="form-checklist-{{ $field->getStatePath() }}-search"
                    placeholder="{{ $field->getSearchPrompt() }}"
                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150 dark:bg-gray-800 dark:border-gray-600 dark:text-white text-base sm:text-sm"
                />
            </div>
        @endif

        @if($field->isBulkToggleable())
            <div class="flex gap-2 px-3 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                <button type="button" @click="selectAll()" data-testid="form-checklist-{{ $field->getStatePath() }}-select-all" class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 font-medium">
                    {{ $field->getSelectAllLabel() }}
                </button>
                <span class="text-gray-300 dark:text-gray-600">|</span>
                <button type="button" @click="deselectAll()" data-testid="form-checklist-{{ $field->getStatePath() }}-deselect-all" class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 font-medium">
                    {{ $field->getDeselectAllLabel() }}
                </button>
            </div>
        @endif

        <div class="max-h-60 overflow-y-auto p-3">
            {{-- Multi-column lists reflow down on narrow screens so option labels
                 stay readable on a phone (a 3–4 wide grid is unusable at 360px). --}}
            @php
                $gridClasses = \Illuminate\Support\Arr::toCssClasses([
                    'grid gap-2',
                    $columnsClass,
                    'grid-cols-1' => $columns === 1,
                    'grid-cols-1 sm:grid-cols-2' => $columns === 2,
                    'grid-cols-1 sm:grid-cols-3' => $columns === 3,
                    'grid-cols-2 sm:grid-cols-4' => $columns === 4,
                ]);
            @endphp

            @if($groups !== [])
                @foreach($groups as $groupLabel => $groupOptions)
                    {{-- The heading goes with its options. Filtering hides each
                         option row on its own, so without this a search left
                         every group's heading standing over nothing — visible
                         only once something combined grouping with search, which
                         is what a permission list does. Matched by the same
                         rule as the rows (`matches()`), so a heading never
                         stands over options that all disappeared. --}}
                    <div
                        class="mb-3 last:mb-0"
                        data-testid="form-checklist-{{ $field->getStatePath() }}-group"
                        @if($field->isSearchable())
                            x-show="matchesAny(@js(array_values($groupOptions)))"
                        @endif
                    >
                        <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $groupLabel }}
                        </p>
                        @include('wire-forms::partials.checkbox-list-options', [
                            'field' => $field,
                            'wireAttr' => $wireAttr,
                            'options' => $groupOptions,
                            'gridClasses' => $gridClasses,
                        ])
                    </div>
                @endforeach
            @else
                @include('wire-forms::partials.checkbox-list-options', compact('field', 'wireAttr', 'options', 'gridClasses'))
            @endif
        </div>
    </div>
@endif

@include('wire-forms::partials.field-wrapper-end')
