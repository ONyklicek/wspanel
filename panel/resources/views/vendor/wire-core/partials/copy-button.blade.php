{{--
    The copy-to-clipboard button — one markup, wherever the affordance appears.

    Variables: $value (what lands on the clipboard), $message (what the feedback
    pill announces), $label (aria-label), $title, $class, $testId, $icon (rendered
    markup).

    The behaviour is not here and not per-button: `copy.js` binds one click
    listener for the document and finds this through `data-copy`, so a page with
    five hundred copyable cells ships five hundred buttons and one listener. That
    replaced an Alpine component per cell, which measured 2 042 B and 11 whitespace
    nodes each against this button's ~180.

    Compiled once per shape and spliced per value — see Foundation\View\CopyButton,
    which is also why the attribute order here is fixed: it is what a table cell
    already emitted, and keeping it means the move cost no bytes.

    On one line on purpose: this is emitted once per copyable thing, and a run of
    whitespace between two tags is one DOM text node the morph walks on every
    commit.
--}}
<button type="button" data-copy="{{ $value }}" data-copy-message="{{ $message }}" data-testid="{{ $testId }}" aria-label="{{ $label }}" title="{{ $title }}" class="{{ $class }}">{!! $icon !!}</button>
