@php use NyonCode\WireForms\Components\RichEditor;
    assert($field instanceof RichEditor);

    $wireModifier = $field->getWireModelModifier();
    $wireAttr = 'wire:model' . ($wireModifier ? ".{$wireModifier}" : '');
    $toolbarButtons = $field->getToolbarButtons();
    $fieldId = $field->getId();

    // Shared editor vocabulary (resources/lang/*/fields.php) — the same keys
    // TiptapEditor and MarkdownEditor title their buttons from, so the three
    // editors read alike and cs comes from the package, not the app.
    $t = static fn (string $key, array $replace = []): string
        => (string) trans("wire-forms::fields.editor.{$key}", $replace);
@endphp

@include('wire-forms::partials.field-assets')

@include('wire-forms::partials.field-wrapper-start')

<div
        {{-- Body registered as `wireRichEditor`; only per-instance config here. --}}
        x-data="wireRichEditor({
            statePath: @js($field->getWireModelAttribute()),
            toolbar: @js($toolbarButtons),
            linkPrompt: @js($t('link_url')),
        })"
        @class([
            'rounded-md border overflow-hidden',
            'border-gray-300 dark:border-gray-600',
            'focus-within:border-primary-500 focus-within:ring-1 focus-within:ring-primary-500',
            'hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150',
            'border-red-500 focus-within:border-red-500 focus-within:ring-red-500' => $errors->has($field->getStatePath()),
        ])
>
    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-0.5 px-2 py-1.5 bg-gray-50 dark:bg-gray-700/50 border-b border-gray-200 dark:border-gray-600">
        @if(in_array('bold', $toolbarButtons))
            <button
                    type="button"
                    @click="exec('bold')" data-testid="form-editor-{{ $field->getStatePath() }}-bold"
                    :class="{ 'bg-gray-200 dark:bg-gray-600': activeFormats.bold }"
                    class="p-1.5 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-150"
                    title="{{ $t('bold') }}"
            >
                {!! icon('bold', 'w-4 h-4', 'w-4 h-4') !!}
            </button>
        @endif

        @if(in_array('italic', $toolbarButtons))
            <button
                    type="button"
                    @click="exec('italic')" data-testid="form-editor-{{ $field->getStatePath() }}-italic"
                    :class="{ 'bg-gray-200 dark:bg-gray-600': activeFormats.italic }"
                    class="p-1.5 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-150"
                    title="{{ $t('italic') }}"
            >
                {!! icon('italic', 'w-4 h-4', 'w-4 h-4') !!}
            </button>
        @endif

        @if(in_array('underline', $toolbarButtons))
            <button
                    type="button"
                    @click="exec('underline')" data-testid="form-editor-{{ $field->getStatePath() }}-underline"
                    :class="{ 'bg-gray-200 dark:bg-gray-600': activeFormats.underline }"
                    class="p-1.5 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-150"
                    title="{{ $t('underline') }}"
            >
                {!! icon('underline', 'w-4 h-4', 'w-4 h-4') !!}
            </button>
        @endif

        @if(in_array('strike', $toolbarButtons))
            <button
                    type="button"
                    @click="exec('strikeThrough')" data-testid="form-editor-{{ $field->getStatePath() }}-strikeThrough"
                    :class="{ 'bg-gray-200 dark:bg-gray-600': activeFormats.strikeThrough }"
                    class="p-1.5 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-150"
                    title="{{ $t('strike') }}"
            >
                {!! icon('strikethrough', 'w-4 h-4', 'w-4 h-4') !!}
            </button>
        @endif

        @if(in_array('bold', $toolbarButtons) || in_array('italic', $toolbarButtons) || in_array('underline', $toolbarButtons) || in_array('strike', $toolbarButtons))
            @if(in_array('h2', $toolbarButtons) || in_array('h3', $toolbarButtons) || in_array('link', $toolbarButtons) || in_array('bulletList', $toolbarButtons) || in_array('orderedList', $toolbarButtons) || in_array('blockquote', $toolbarButtons) || in_array('codeBlock', $toolbarButtons))
                <div class="w-px h-5 bg-gray-300 dark:bg-gray-500 mx-1"></div>
            @endif
        @endif

        @if(in_array('h2', $toolbarButtons))
            <button
                    type="button"
                    @click="exec('formatBlock', 'h2')"
                    class="p-1.5 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-150"
                    title="{{ $t('heading', ['level' => 2]) }}"
            >
                {!! icon('forms:heading-2', 'w-4 h-4') !!}
            </button>
        @endif

        @if(in_array('h3', $toolbarButtons))
            <button
                    type="button"
                    @click="exec('formatBlock', 'h3')"
                    class="p-1.5 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-150"
                    title="{{ $t('heading', ['level' => 3]) }}"
            >
                {!! icon('forms:heading-3', 'w-4 h-4') !!}
            </button>
        @endif

        @if(in_array('bulletList', $toolbarButtons))
            <button
                    type="button"
                    @click="exec('insertUnorderedList')" data-testid="form-editor-{{ $field->getStatePath() }}-insertUnorderedList"
                    :class="{ 'bg-gray-200 dark:bg-gray-600': activeFormats.insertUnorderedList }"
                    class="p-1.5 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-150"
                    title="{{ $t('bullet_list') }}"
            >
                {!! icon('list-bullet', 'w-4 h-4', 'w-4 h-4') !!}
            </button>
        @endif

        @if(in_array('orderedList', $toolbarButtons))
            <button
                    type="button"
                    @click="exec('insertOrderedList')" data-testid="form-editor-{{ $field->getStatePath() }}-insertOrderedList"
                    :class="{ 'bg-gray-200 dark:bg-gray-600': activeFormats.insertOrderedList }"
                    class="p-1.5 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-150"
                    title="{{ $t('ordered_list') }}"
            >
                {!! icon('numbered-list', 'w-4 h-4', 'w-4 h-4') !!}
            </button>
        @endif

        @if(in_array('blockquote', $toolbarButtons))
            <button
                    type="button"
                    @click="exec('formatBlock', 'blockquote')"
                    class="p-1.5 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-150"
                    title="{{ $t('blockquote') }}"
            >
                {!! icon('forms:blockquote', 'w-4 h-4') !!}
            </button>
        @endif

        @if(in_array('codeBlock', $toolbarButtons))
            <button
                    type="button"
                    @click="exec('formatBlock', 'pre')"
                    class="p-1.5 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-150"
                    title="{{ $t('code_block') }}"
            >
                {!! icon('code-bracket', 'w-4 h-4', 'w-4 h-4') !!}
            </button>
        @endif

        @if(in_array('link', $toolbarButtons))
            @if(in_array('h2', $toolbarButtons) || in_array('h3', $toolbarButtons) || in_array('bulletList', $toolbarButtons) || in_array('orderedList', $toolbarButtons) || in_array('blockquote', $toolbarButtons) || in_array('codeBlock', $toolbarButtons))
                <div class="w-px h-5 bg-gray-300 dark:bg-gray-500 mx-1"></div>
            @endif

            <button
                    type="button"
                    @click="insertLink()"
                    class="p-1.5 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-150"
                    title="{{ $t('link') }}"
            >
                {!! icon('link', 'w-4 h-4', 'w-4 h-4') !!}
            </button>
        @endif

        @if(in_array('undo', $toolbarButtons) || in_array('redo', $toolbarButtons))
            <div class="w-px h-5 bg-gray-300 dark:bg-gray-500 mx-1"></div>
        @endif

        @if(in_array('undo', $toolbarButtons))
            <button
                    type="button"
                    @click="exec('undo')" data-testid="form-editor-{{ $field->getStatePath() }}-undo"
                    class="p-1.5 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-150"
                    title="{{ $t('undo') }}"
            >
                {!! icon('arrow-uturn-left', 'w-4 h-4', 'w-4 h-4') !!}
            </button>
        @endif

        @if(in_array('redo', $toolbarButtons))
            <button
                    type="button"
                    @click="exec('redo')" data-testid="form-editor-{{ $field->getStatePath() }}-redo"
                    class="p-1.5 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-150"
                    title="{{ $t('redo') }}"
            >
                {!! icon('arrow-uturn-right', 'w-4 h-4', 'w-4 h-4') !!}
            </button>
        @endif
    </div>

    {{-- Editable area --}}
    <div
            wire:ignore
            x-ref="editor" data-testid="form-editor-{{ $field->getStatePath() }}"
            contenteditable="{{ $field->isDisabled() || $field->isReadOnly() ? 'false' : 'true' }}"
            @input="onInput()"
            @click="updateActiveFormats()"
            @keyup="updateActiveFormats()"
            @if($field->getPlaceholder()) data-placeholder="{{ $field->getPlaceholder() }}" @endif
            @class([
                'min-h-[10rem] px-4 py-3 text-base sm:text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-800',
                'focus:outline-none',
                'prose prose-base sm:prose-sm dark:prose-invert max-w-none',
                '[&:empty]:before:content-[attr(data-placeholder)] [&:empty]:before:text-gray-500 dark:[&:empty]:before:text-gray-400',
                'disabled:opacity-50' => $field->isDisabled(),
            ])
    ></div>

    {{-- Hidden textarea: wire:model.live pushes value to server on each input event --}}
    <textarea
            id="{{ $fieldId }}"
            x-ref="textarea"
            wire:model.live="{{ $field->getWireModelAttribute() }}"
            class="sr-only"
            tabindex="-1"
            aria-hidden="true"
    ></textarea>
</div>

@include('wire-forms::partials.field-wrapper-end')
