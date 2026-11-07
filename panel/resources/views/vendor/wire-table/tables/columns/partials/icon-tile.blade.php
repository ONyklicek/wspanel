{{-- The list archetype's row anchor: a rounded, tinted square with the type's
     icon in it. Its own partial rather than a string built in PHP — markup lives
     in a template (AI_CODING_STANDARD § Rendering), and a consumer who wants a
     different tile publishes this one file.

     The glyph inherits `currentColor` from the tile, so ground and ink are one
     decision and cannot drift apart.

     Sized to be a row anchor rather than an inline glyph: at 36px it holds the
     two lines of text beside it and gives the list a left edge the eye can run
     down, which a 16px icon sitting on the first line does not.

     Variables: $classes (ground + ink for the role), $icon (rendered svg). --}}
<span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] {{ $classes }}">{!! $icon !!}</span>
