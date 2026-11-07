{{-- Copy affordance around an already-rendered cell.

     The wrapper is markup and stays in the template; only the button arrives as
     a string, because it has an owner — core's `Foundation\View\CopyButton`,
     the same affordance an infolist entry uses, compiled once per shape there
     and spliced, so this costs no view render per cell.

     `tables.columns.text` writes the same wrapper inline: it composes the whole
     cell without an intermediate string, so it has nothing to hand this partial.
     Keep the two in step. --}}
@php
    /** @var string $content already-rendered cell markup, spliced in verbatim */
    /** @var mixed $copyValue the value the button puts on the clipboard */
    /** @var string|null $copyMessage what to announce afterwards */

    // The table passes its own strings. `wire-table::messages.copy` is what a
    // consumer has already translated and what this cell has always announced;
    // reaching for core's key instead would change the page to save a parameter.
    $copyButtonHtml = app(\NyonCode\WireCore\Foundation\View\CopyButton::class)->render(
        value: (string) $copyValue,
        message: (string) $copyMessage,
        label: $copyMessage ?? __('wire-table::messages.copy'),
        title: __('wire-table::messages.copy'),
        testId: 'cell-copy',
    );
@endphp
<span class="inline-flex items-center gap-1.5 group">{!! $content !!}{!! $copyButtonHtml !!}</span>
