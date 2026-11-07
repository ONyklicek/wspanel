@php
    /** @var \App\Forms\Components\CodeIde $field */
    $entangleModifier = $field->getEntangleModifier();
    $labels = ['php' => 'PHP', 'javascript' => 'JavaScript', 'typescript' => 'TypeScript', 'css' => 'CSS', 'html' => 'HTML', 'json' => 'JSON',
        'yaml' => 'YAML', 'markdown' => 'Markdown', 'sql' => 'SQL', 'shell' => 'Shell', 'dotenv' => '.env', 'xml' => 'XML', 'plaintext' => 'Text'];
@endphp

@include('wire-forms::partials.field-wrapper-start')

<div
    wire:ignore
    x-data="wspanelCodeIde({
        state: @entangle($field->getWireModelAttribute()){{ $entangleModifier ? '.' . $entangleModifier : '' }},
        language: @js($field->getLanguage()),
        readOnly: @js($field->isReadOnly()),
        minHeight: @js($field->getMinHeight()),
    })"
    @class([
        'overflow-hidden rounded-md border',
        'border-gray-300 dark:border-gray-600',
        'border-red-500' => $errors->has($field->getStatePath()),
    ])
>
    <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-3 py-1.5 text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
        <span class="font-medium uppercase tracking-wide">{{ $labels[$field->getLanguage()] ?? $field->getLanguage() }}</span>
        <span class="flex items-center gap-3">
            <span x-text="'řádek ' + cursor.replace(':', ', sloupec ')"></span>
            <span class="hidden sm:inline">Ctrl/⌘ F hledat</span>
            <template x-if="canFormat">
                <button type="button" x-on:click="format()" x-bind:disabled="formatting"
                    class="rounded border border-gray-300 bg-white px-2 py-0.5 font-medium text-gray-700 hover:bg-gray-100 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                    title="Shift+Alt+F">
                    <span x-text="formatting ? 'Formátuji…' : 'Naformátovat'"></span>
                </button>
            </template>
        </span>
    </div>
    <div x-show="loading" class="px-3 py-6 text-sm text-gray-500">Načítám editor…</div>
    <div x-ref="editor" class="text-left"></div>
    <template x-if="formatError">
        <div class="border-t border-red-200 bg-red-50 px-3 py-1.5 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" x-text="'Naformátovat nejde: ' + formatError"></div>
    </template>
</div>

@include('wire-forms::partials.field-wrapper-end')
