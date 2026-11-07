@php
    use NyonCode\WireForms\Components\KeyValue;
    assert($field instanceof KeyValue);
    // @entangle takes no wire:model modifiers — see CanBeLive::getEntangleModifier().
    $entangleModifier = $field->getEntangleModifier();
@endphp

@include('wire-forms::partials.field-assets')

@include('wire-forms::partials.field-wrapper-start')

<div
    {{-- Body registered as `wireKeyValue`; only per-instance config here. --}}
    x-data="wireKeyValue({
        state: @entangle($field->getWireModelAttribute()){{ $entangleModifier ? '.' . $entangleModifier : '' }},
    })"
    class="rounded-md border border-gray-300 dark:border-gray-600 overflow-hidden"
>
    {{-- Column headers --}}
    <div class="grid grid-cols-[1fr_1fr_auto] gap-0 bg-gray-50 dark:bg-gray-700/50 border-b border-gray-200 dark:border-gray-600 px-3 py-1.5">
        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ $field->getKeyLabel() }}</span>
        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ $field->getValueLabel() }}</span>
        <span class="w-6"></span>
    </div>

    {{-- Rows --}}
    <div class="divide-y divide-gray-200 dark:divide-gray-700">
        <template x-for="(pair, index) in pairs" :key="index">
            <div class="grid grid-cols-[1fr_1fr_auto] items-center gap-0">
                <input
                    type="text"
                    :value="pair.key"
                    @input="updateKey(index, $event.target.value)"
                    @if(!$field->isKeyEditable()) readonly @endif
                    placeholder="{{ $field->getKeyPlaceholder() }}"
                    class="border-0 border-r border-gray-200 dark:border-gray-700 ring-0 shadow-none focus:ring-inset focus:ring-1 focus:ring-primary-500 bg-transparent px-3 py-2 text-base sm:text-sm text-gray-900 dark:text-white placeholder:text-gray-500 dark:placeholder:text-gray-400 focus:outline-none @if(!$field->isKeyEditable()) bg-gray-50 dark:bg-gray-700/30 text-gray-500 @endif"
                />
                <input
                    type="text"
                    :value="pair.value"
                    @input="updateValue(index, $event.target.value)"
                    @if($field->isDisabled()) disabled @endif
                    @if($field->isReadOnly()) readonly @endif
                    placeholder="{{ $field->getValuePlaceholder() }}"
                    class="border-0 border-r border-gray-200 dark:border-gray-700 ring-0 shadow-none focus:ring-inset focus:ring-1 focus:ring-primary-500 bg-transparent px-3 py-2 text-base sm:text-sm text-gray-900 dark:text-white placeholder:text-gray-500 dark:placeholder:text-gray-400 focus:outline-none disabled:opacity-50"
                />
                @if($field->isDeletable())
                    <button type="button" @click="removePair(index)" :data-testid="'form-keyvalue-{{ $field->getStatePath() }}-remove-' + index"
                        class="flex items-center justify-center w-9 h-full text-gray-500 hover:text-red-500 dark:hover:text-red-400 transition-colors">
                        {!! icon('x-mark', 'w-4 h-4', 'w-4 h-4') !!}
                    </button>
                @else
                    <span class="w-9"></span>
                @endif
            </div>
        </template>

        <template x-if="pairs.length === 0">
            <div class="px-3 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('No entries') }}
            </div>
        </template>
    </div>

    {{-- Add button --}}
    @if($field->isAddable())
        <div class="border-t border-gray-200 dark:border-gray-700 px-3 py-2 bg-gray-50 dark:bg-gray-700/30">
            <button type="button" @click="addPair()" data-testid="form-keyvalue-{{ $field->getStatePath() }}-add"
                class="inline-flex items-center gap-1.5 text-sm text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium transition-colors">
                {!! icon('plus', 'w-4 h-4', 'w-4 h-4') !!}
                {{ __('Add entry') }}
            </button>
        </div>
    @endif
</div>

@include('wire-forms::partials.field-wrapper-end')
