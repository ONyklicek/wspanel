@php
    use NyonCode\LaravelPackageToolkit\Support\PublishedAssets;
    use NyonCode\WireForms\Components\TiptapEditor;
    use NyonCode\WireForms\WireFormsServiceProvider;
    assert($field instanceof TiptapEditor);

    $toolbar   = $field->getToolbarButtons();
    $config    = $field->getAlpineConfig();
    $fieldId   = $field->getId();
    $statePath = $field->getStatePath();

    // Match the standard field binding: deferred by default (no server round-trip
    // per keystroke), live + debounce only when the field opts in via ->live().
    $wireModifier     = $field->getWireModelModifier();
    $debounceModifier = $field->getDebounceModifier();
    $wireAttr         = 'wire:model'.($wireModifier ? ".{$wireModifier}" : '').$debounceModifier;

    // Pre-bundled, self-registering editor JS served straight from the package
    // (no npm install / build step on the consumer side). ESM code-split: the core
    // editor entry pulls the shared chunk; the opt-in extension addon (below) is a
    // sibling entry sharing that same chunk, injected only when this field needs it.
    // The bundle stays outside the AssetManager registry (it is on-request by
    // nature), but it resolves through the same published-assets owner, so an app
    // that published gets `public/vendor/wire-forms/tiptap/…` here too. The publish
    // mirrors the directory, so the entry's relative chunk import still resolves.
    $tiptapDir    = WireFormsServiceProvider::ASSETS_PATH.'/tiptap';
    $published    = app(PublishedAssets::class);

    $tiptapUrl = static function (string $file) use ($tiptapDir, $published): string {
        $version = @filemtime($tiptapDir.'/'.$file) ?: null;

        return $published->url('wire-forms', $tiptapDir.'/'.$file)
            ?? route('wire-forms.tiptap', ['file' => $file]).($version ? '?id='.$version : '');
    };

    $assetUrl   = $tiptapUrl('tiptap-editor.js');
    $needsAddon = $field->needsExtensionAddon();
    $addonUrl   = $needsAddon ? $tiptapUrl('tiptap-editor-addons.js') : null;
    // Third entry, same deal: an editor with no ->mentions() never downloads the
    // mention node or the suggestion engine behind it.
    $needsMentions = $field->needsMentionAddon();
    $mentionsUrl   = $needsMentions ? $tiptapUrl('tiptap-editor-mentions.js') : null;

    // Button icons + Alpine expressions, keyed by button name. The icon is a
    // name in the `forms` set, never markup: the glyphs live in
    // Support\Icons\FormsIconSet and reach the button through icon(), which
    // is the one pipeline for every icon the framework draws. Titles come
    // from the package's shared editor vocabulary (resources/lang/*/fields.php),
    // so the toolbar follows the app locale — cs ships with the package.
    $t = static fn (string $key, array $replace = []): string
        => (string) trans("wire-forms::fields.editor.{$key}", $replace);

    // Active states read through `$data`, not a bare `isActive(...)`: wire:navigate
    // can run a toolbar effect Alpine had already queued after the editor's scope
    // is gone, and a bare name then throws "isActive is not defined" while
    // `$data.isActive?.(...)` is just undefined.
    $btns = [
        'bold'         => ['action' => 'toggleBold()',              'active' => "\$data.isActive?.('bold')",           'title' => $t('bold'),                            'icon' => 'forms:bold'],
        'italic'       => ['action' => 'toggleItalic()',            'active' => "\$data.isActive?.('italic')",         'title' => $t('italic'),                          'icon' => 'forms:italic'],
        'underline'    => ['action' => 'toggleUnderline()',         'active' => "\$data.isActive?.('underline')",      'title' => $t('underline'),                       'icon' => 'forms:underline'],
        'strike'       => ['action' => 'toggleStrike()',            'active' => "\$data.isActive?.('strike')",         'title' => $t('strike'),                          'icon' => 'forms:strike'],
        'code'         => ['action' => 'toggleCode()',              'active' => "\$data.isActive?.('code')",           'title' => $t('code'),                            'icon' => 'forms:code'],
        'highlight'    => ['action' => 'toggleHighlight()',         'active' => "\$data.isActive?.('highlight')",      'title' => $t('highlight'),                       'icon' => 'forms:highlight'],
        'h1'           => ['action' => 'setHeading(1)',             'active' => "\$data.isActive?.('heading', { level: 1 })", 'title' => $t('heading', ['level' => 1]),  'label' => 'H1'],
        'h2'           => ['action' => 'setHeading(2)',             'active' => "\$data.isActive?.('heading', { level: 2 })", 'title' => $t('heading', ['level' => 2]),  'label' => 'H2'],
        'h3'           => ['action' => 'setHeading(3)',             'active' => "\$data.isActive?.('heading', { level: 3 })", 'title' => $t('heading', ['level' => 3]),  'label' => 'H3'],
        'bulletList'   => ['action' => 'toggleBulletList()',        'active' => "\$data.isActive?.('bulletList')",     'title' => $t('bullet_list'),                     'icon' => 'forms:bullet-list'],
        'orderedList'  => ['action' => 'toggleOrderedList()',       'active' => "\$data.isActive?.('orderedList')",    'title' => $t('ordered_list'),                    'icon' => 'forms:ordered-list'],
        'blockquote'   => ['action' => 'toggleBlockquote()',        'active' => "\$data.isActive?.('blockquote')",     'title' => $t('blockquote'),                      'icon' => 'forms:blockquote'],
        'codeBlock'    => ['action' => 'toggleCodeBlock()',         'active' => "\$data.isActive?.('codeBlock')",      'title' => $t('code_block'),                      'icon' => 'forms:code-block'],
        'link'         => ['action' => 'insertLink()',              'active' => "\$data.isActive?.('link')",           'title' => $t('link'),                            'icon' => 'forms:link'],
        'image'        => ['action' => 'insertImage()',             'active' => 'false',                      'title' => $t('image'),                           'icon' => 'forms:image'],
        'table'        => ['action' => 'insertTable()',             'active' => "\$data.isActive?.('table')",          'title' => $t('table'),                           'icon' => 'forms:table'],
        'alignLeft'    => ['action' => "setAlign('left')",          'active' => "\$data.isActive?.({ textAlign: 'left' })",   'title' => $t('align_left'),               'icon' => 'forms:align-left'],
        'alignCenter'  => ['action' => "setAlign('center')",        'active' => "\$data.isActive?.({ textAlign: 'center' })", 'title' => $t('align_center'),             'icon' => 'forms:align-center'],
        'alignRight'   => ['action' => "setAlign('right')",         'active' => "\$data.isActive?.({ textAlign: 'right' })",  'title' => $t('align_right'),              'icon' => 'forms:align-right'],
        'undo'         => ['action' => 'undo()',                    'active' => 'false',                      'title' => $t('undo'),                            'icon' => 'forms:undo'],
        'redo'         => ['action' => 'redo()',                    'active' => 'false',                      'title' => $t('redo'),                            'icon' => 'forms:redo'],
    ];

    $hasToolbar = count($toolbar) > 0;
@endphp

@include('wire-forms::partials.field-wrapper-start')

@once
<style>
    /* Make the whole editor box (not just the first line) clickable and typeable:
       the contenteditable fills the configured min-height and carries the padding. */
    .tiptap-content { cursor: text; }
    .tiptap-content .ProseMirror {
        outline: none;
        min-height: var(--tt-min-height, 240px);
        padding: .75rem 1rem;
    }
    /* iOS Safari zooms the page into any focused editor under 16px. */
    @media (max-width: 639.98px) {
        .tiptap-content .ProseMirror { font-size: 1rem; }
    }
    .tiptap-content .ProseMirror p.is-editor-empty:first-child::before {
        content: attr(data-placeholder); float: left;
        color: #9ca3af; pointer-events: none; height: 0;
    }
    .tiptap-content .ProseMirror h1 { font-size: 1.5rem; font-weight: 700; margin: 1rem 0 .5rem; }
    .tiptap-content .ProseMirror h2 { font-size: 1.25rem; font-weight: 700; margin: 1rem 0 .5rem; }
    .tiptap-content .ProseMirror h3 { font-size: 1.1rem; font-weight: 600; margin: .75rem 0 .375rem; }
    .tiptap-content .ProseMirror ul { list-style: disc; padding-left: 1.5rem; margin: .5rem 0; }
    .tiptap-content .ProseMirror ol { list-style: decimal; padding-left: 1.5rem; margin: .5rem 0; }
    .tiptap-content .ProseMirror blockquote { border-left: 3px solid #d1d5db; padding-left: .75rem; color: #6b7280; font-style: italic; margin: .5rem 0; }
    .tiptap-content .ProseMirror pre { background: #1e293b; color: #e2e8f0; padding: .75rem 1rem; border-radius: .375rem; font-family: monospace; font-size: .8125rem; overflow-x: auto; margin: .5rem 0; }
    .tiptap-content .ProseMirror code { background: #f1f5f9; color: #dc2626; padding: .125rem .25rem; border-radius: .25rem; font-family: monospace; font-size: .85em; }
    .tiptap-content .ProseMirror pre code { background: none; color: inherit; padding: 0; }
    .tiptap-content .ProseMirror a { color: #2563eb; text-decoration: underline; cursor: pointer; }
    .tiptap-content .ProseMirror mark { background: #fef08a; border-radius: .125rem; }
    .tiptap-content .ProseMirror img { max-width: 100%; height: auto; border-radius: .375rem; }
    .tiptap-content .ProseMirror table { border-collapse: collapse; width: 100%; margin: .5rem 0; }
    .tiptap-content .ProseMirror td, .tiptap-content .ProseMirror th { border: 1px solid #d1d5db; padding: .375rem .5rem; vertical-align: top; min-width: 1.5rem; }
    .tiptap-content .ProseMirror th { background: #f9fafb; font-weight: 600; }
    .tiptap-content .ProseMirror .selectedCell { background: #eff6ff; }
    .dark .tiptap-content .ProseMirror code { background: #374151; color: #fca5a5; }
    .dark .tiptap-content .ProseMirror pre code { background: none; color: inherit; }
    .dark .tiptap-content .ProseMirror blockquote { border-left-color: #4b5563; color: #9ca3af; }
    .dark .tiptap-content .ProseMirror a { color: #60a5fa; }
    .dark .tiptap-content .ProseMirror mark { color: #111827; }
    .dark .tiptap-content .ProseMirror td, .dark .tiptap-content .ProseMirror th { border-color: #4b5563; }
    .dark .tiptap-content .ProseMirror th { background: #374151; }
    .dark .tiptap-content .ProseMirror .selectedCell { background: #1e3a5f; }

    /* A mention reads as one object, so it is styled as a pill and made
       atomic — the node is already atom:true in TipTap, this only says so. */
    .tiptap-content .ProseMirror .wire-mention {
        background: #eff6ff; color: #1d4ed8; border-radius: .25rem;
        padding: .0625rem .25rem; white-space: nowrap;
    }
    .dark .tiptap-content .ProseMirror .wire-mention { background: #1e3a5f; color: #93c5fd; }

    /* The suggestion list is appended to <body> (it follows the caret, not an
       element), so it carries its own box rather than inheriting the field's. */
    .wire-mention-suggestions {
        position: absolute; z-index: 60; min-width: 12rem; max-width: 20rem; max-height: 15rem;
        overflow-y: auto; padding: .25rem; border-radius: .375rem;
        background: #fff; border: 1px solid #d1d5db; box-shadow: 0 10px 15px -3px rgb(0 0 0 / .1);
        font-size: .875rem;
    }
    .wire-mention-group {
        padding: .25rem .5rem; font-size: .6875rem; font-weight: 600; text-transform: uppercase;
        letter-spacing: .05em; color: #9ca3af;
    }
    .wire-mention-item {
        display: block; width: 100%; text-align: left; padding: .3125rem .5rem;
        border-radius: .25rem; color: #111827; background: none; border: 0; cursor: pointer;
    }
    .wire-mention-item.is-selected, .wire-mention-item:hover { background: #eff6ff; }
    .dark .wire-mention-suggestions { background: #1f2937; border-color: #4b5563; }
    .dark .wire-mention-item { color: #f9fafb; }
    .dark .wire-mention-item.is-selected, .dark .wire-mention-item:hover { background: #374151; }
</style>
@endonce

{{-- Load the pre-bundled editor JS via Livewire's @assets directive so it runs
     once and also when the field renders inside a Livewire-loaded modal (AJAX),
     where script tags injected through DOM morphing would never execute.
     type="module" because the bundle is ESM (code-split with a shared chunk); the
     addon (when this field enables tables/images/…) publishes the extension
     registry the editor reads at init, and shares the same core chunk. --}}
@assets
@if($needsAddon)
<script type="module" src="{{ $addonUrl }}"></script>
@endif
@if($needsMentions)
<script type="module" src="{{ $mentionsUrl }}"></script>
@endif
<script type="module" src="{{ $assetUrl }}"></script>
@endassets

<div
    x-data="tiptapEditor(@js($config))"
    @class([
        'rounded-md border overflow-hidden',
        'border-gray-300 dark:border-gray-600',
        'border-red-500' => $errors->has($statePath),
    ])
>
    {{-- ─── Toolbar ─────────────────────────────────────────────── --}}
    @if($hasToolbar)
        <div @class([
            'flex flex-wrap items-center gap-0.5 px-2 py-1.5 border-b',
            'bg-gray-50 dark:bg-gray-700/50 border-gray-300 dark:border-gray-600',
            'opacity-50 pointer-events-none' => $field->isDisabled() || $field->isReadOnly(),
        ])>
            @foreach($toolbar as $btn)
                @if($btn === '|')
                    <div class="w-px h-5 bg-gray-300 dark:bg-gray-500 mx-1"></div>
                @elseif(isset($btns[$btn]))
                    @php $b = $btns[$btn]; @endphp
                    <button
                        type="button"
                        @mousedown.prevent
                        @click="{{ $b['action'] }}" data-testid="form-editor-{{ $field->getStatePath() }}-{{ $loop->index }}"
                        :class="{ 'bg-gray-200 dark:bg-gray-600 text-gray-900 dark:text-white': {{ $b['active'] }} }"
                        title="{{ $b['title'] }}"
                        class="inline-flex items-center justify-center w-7 h-7 rounded-sm text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-100"
                    >
                        @if(isset($b['label']))
                            <span class="text-xs font-semibold leading-none">{{ $b['label'] }}</span>
                        @else
                            {!! icon($b['icon'], 'w-4 h-4') !!}
                        @endif
                    </button>
                @endif
            @endforeach
        </div>
    @endif

    {{-- ─── Editor mount point ─────────────────────────────────────── --}}
    <div
        x-ref="editorContent" data-testid="form-editor-{{ $field->getStatePath() }}"
        wire:ignore
        style="--tt-min-height: {{ $field->getMinHeight() }}px"
        @class([
            'tiptap-content text-sm text-gray-900 dark:text-white bg-white dark:bg-gray-800',
            'focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500',
            'opacity-60 cursor-not-allowed' => $field->isDisabled(),
        ])
    ></div>

    {{-- ─── Hidden input: carries wire:model to Livewire ──────────────── --}}
    <input
        type="hidden"
        id="{{ $fieldId }}"
        x-ref="hiddenInput"
        {{ $wireAttr }}="{{ $field->getWireModelAttribute() }}"
    />

    {{-- ─── Character count footer ──────────────────────────────────── --}}
    @if($field->getMaxLength())
        <div class="flex justify-end px-3 py-1 bg-gray-50 dark:bg-gray-700/30 border-t border-gray-300 dark:border-gray-600 text-xs text-gray-500 dark:text-gray-400">
            <span x-text="($data.characterCount ?? 0) + ' / {{ $field->getMaxLength() }}'"></span>
        </div>
    @endif
</div>

@include('wire-forms::partials.field-wrapper-end')
