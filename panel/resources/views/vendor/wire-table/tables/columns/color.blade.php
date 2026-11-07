{{-- ColorColumn cell. Column owns state/sanitisation; this partial owns markup.

     The colour itself is `partials.color-swatch`, because the copy affordance
     wraps an already-rendered fragment rather than nesting around one — so the
     copyable arm renders the swatch and hands it over, and the plain arm just
     includes it. Both cost one render per distinct colour rather than per row:
     the column memoises this view by its data (renderViewCached).

     The comment between `@else` and the include is load-bearing, not decoration.
     Blade will not compile a directive that follows a word character, so
     `@else` immediately followed by an include leaves the include in the output
     as literal text. --}}
@php
    /** @var string $swatch CSS color already validated by Foundation\Support\CssColor */
    /** @var string $displayValue literal value shown next to the swatch ('' when swatch-only) */
    /** @var bool $copyable */
    /** @var string $copyValue */
    /** @var string $copyMessage */
@endphp

@if($copyable)
    @include('wire-table::tables.columns.partials.copyable', [
        // Trimmed: the swatch and the copy button sit side by side in an
        // inline-flex, so the partial's own trailing newline would be a rendered
        // gap between them (and a DOM text node the morph walks).
        'content' => trim(view('wire-table::tables.columns.partials.color-swatch', [
            'swatch' => $swatch,
            'displayValue' => $displayValue,
        ])->render()),
        'copyValue' => $copyValue,
        'copyMessage' => $copyMessage,
    ])
@else
    @include('wire-table::tables.columns.partials.color-swatch', [
        'swatch' => $swatch,
        'displayValue' => $displayValue,
    ])
@endif
