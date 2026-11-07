{{--
    The two layers that sit under a pinned cell's content, between the row's
    background and the buttons: an opaque surface, and the row's own colour
    inherited back on top of it. Why it takes both, and why neither can be an
    opaque colour map instead: Support\StickyColumn.

    Variables: $surfaceClass (table-level, a literal utility pair).

    `inset-0` covers the cell's padding box, so the divider border on the cell
    itself stays visible; `pointer-events-none` keeps the click on the cell, where
    the row controller listens for it.

    Mind the whitespace: the tags touch on purpose. This is spliced into the
    action cell, which is emitted once per row, and a run of whitespace between
    two tags is a DOM text node the morph walks on every commit.
--}}<div aria-hidden="true" class="pointer-events-none absolute inset-0 {{ $surfaceClass }}"></div><div aria-hidden="true" class="pointer-events-none absolute inset-0 bg-inherit"></div>