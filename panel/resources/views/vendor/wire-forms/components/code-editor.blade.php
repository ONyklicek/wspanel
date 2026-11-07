@php
    use NyonCode\WireForms\Components\CodeEditor;
    assert($field instanceof CodeEditor);
    // @entangle takes no wire:model modifiers — see CanBeLive::getEntangleModifier().
    $entangleModifier = $field->getEntangleModifier();
@endphp

@include('wire-forms::partials.field-assets')

@include('wire-forms::partials.field-wrapper-start')

<div
    {{-- Body registered as `wireCodeEditor`; only per-instance config here. --}}
    x-data="wireCodeEditor({
        state: @entangle($field->getWireModelAttribute()){{ $entangleModifier ? '.' . $entangleModifier : '' }},
    })"
    @class([
        'rounded-md border overflow-hidden font-mono text-sm',
        'border-gray-300 dark:border-gray-600',
        'focus-within:border-primary-500 focus-within:ring-1 focus-within:ring-primary-500',
        'border-red-500 focus-within:border-red-500 focus-within:ring-red-500' => $errors->has($field->getStatePath()),
    ])
>
    {{-- Header bar --}}
    <div class="flex items-center justify-between px-3 py-1.5 bg-gray-800 dark:bg-gray-900 border-b border-gray-700">
        <div class="flex gap-1.5">
            <span class="w-3 h-3 rounded-full bg-red-500 opacity-80"></span>
            <span class="w-3 h-3 rounded-full bg-yellow-500 opacity-80"></span>
            <span class="w-3 h-3 rounded-full bg-green-500 opacity-80"></span>
        </div>
        <span class="text-xs text-gray-400 uppercase tracking-wide">{{ $field->getLanguage() }}</span>
        <span class="w-12"></span>
    </div>

    {{-- Editor body --}}
    <div class="flex bg-gray-900 dark:bg-gray-950" style="min-height: {{ $field->getMinHeight() }}px">

        @if($field->hasLineNumbers())
            {{-- Line numbers --}}
            <div
                class="select-none text-right text-gray-400 px-3 py-3 text-xs leading-5 bg-gray-900 dark:bg-gray-950 border-r border-gray-700 shrink-0 min-w-[2.5rem]"
                aria-hidden="true"
            >
                <template x-for="(line, i) in lines" :key="i">
                    <div x-text="i + 1" class="leading-5"></div>
                </template>
            </div>
        @endif

        <textarea
            x-model="content"
            @keydown.tab="onTab($event)"
            rows="10"
            style="min-height: {{ $field->getMinHeight() }}px"
            @if($field->getPlaceholder()) placeholder="{{ $field->getPlaceholder() }}" @endif
            @if($field->getMaxLength()) maxlength="{{ $field->getMaxLength() }}" @endif
            @if($field->isDisabled()) disabled @endif
            @if($field->isReadOnly()) readonly @endif
            spellcheck="false"
            autocorrect="off"
            autocapitalize="off"
            class="flex-1 block w-full bg-transparent border-0 ring-0 shadow-none focus:ring-0 focus:border-0 focus:outline-none px-3 py-3 text-gray-100 text-base sm:text-xs leading-5 resize-none placeholder-gray-600 disabled:opacity-50 disabled:cursor-not-allowed"
        ></textarea>
    </div>

    @if($field->getMaxLength())
        <div class="flex justify-end px-3 py-1 bg-gray-800 dark:bg-gray-900 border-t border-gray-700">
            <span class="text-xs text-gray-500 dark:text-gray-400" x-text="(content || '').length + ' / {{ $field->getMaxLength() }}'"></span>
        </div>
    @endif
</div>

@include('wire-forms::partials.field-wrapper-end')
