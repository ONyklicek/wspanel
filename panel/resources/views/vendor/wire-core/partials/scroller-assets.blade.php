{{-- Any clipping scroll region, told to draw a scrollbar the user can see.

     Owned here rather than by the surface that first needed it (wire-table's
     data region): a table three screens wide, a table widget in a dashboard
     card, and a repeater with more columns than its field is wide are the same
     box with the same silence, and wire-forms sits below wire-table in the
     graph — it cannot include a partial from it.

     A region that clips is clipped silently: `overflow` draws nothing at the
     edge it cuts, so a region wider than its box looks, at rest, exactly like
     one that fits — and the framework's answer used to be three gradients laid
     over the edges, driven from the scroller's own offsets. They said "there is
     more" and nothing else, while dimming whatever they covered: the pinned
     header row, the rules between rows, the first characters of the first
     column. The box already has a control that says how much more there is AND
     takes you there, and it costs no overlay: its scrollbar.

     The only reason that was not enough is that the platform hides it. macOS and
     iOS render an **overlay** scrollbar — drawn over the content, faded out a
     second after the last scroll — so a table nobody has touched yet shows
     nothing at all, which is the exact moment the hint is needed. Windows and
     Linux have always shown a classic one, and Firefox shows one everywhere.

     Declaring `::-webkit-scrollbar` is what opts an element out of the overlay
     scrollbar in WebKit and Blink and back onto a classic, laid-out one: painted
     for as long as the content overflows, and absent when it does not. It is a
     property of the element, so it needs no state, no observer, and nothing that
     can be stale after a morph — which is three fewer things than the gradients
     needed to be right.

     `@supports not selector(::-webkit-scrollbar)` is not decoration. Chrome 121
     and later implement the standard `scrollbar-width` / `scrollbar-color`, and
     **an element that sets either one ignores every `::-webkit-scrollbar` rule**
     — so writing both unconditionally would have handed Chrome back the overlay
     scrollbar this partial exists to defeat. Firefox does not support the
     `selector()` query at all, which is what makes the guard select exactly the
     engine that needs the standard properties.

     Inline rather than a Tailwind utility for the reason `sortable-list-assets`
     gives: none of this is expressible as a utility, a consumer's extractor
     never sees a pseudo-element, and `@assets` emits the block once per page
     however many scrollers are on it. --}}
@assets
<style>
    /* Firefox, and any engine without the WebKit pseudo-elements. */
    @supports not selector(::-webkit-scrollbar) {
        .wire-scroller {
            scrollbar-width: thin;
            scrollbar-color: rgb(203 213 225) transparent;
        }

        .dark .wire-scroller {
            scrollbar-color: rgb(71 85 105) transparent;
        }
    }

    /* `-webkit-appearance: none` is the line that matters: without it macOS
       keeps the overlay scrollbar and everything below is ignored. */
    .wire-scroller::-webkit-scrollbar {
        -webkit-appearance: none;
        width: 10px;
        height: 10px;
    }

    .wire-scroller::-webkit-scrollbar-track,
    .wire-scroller::-webkit-scrollbar-corner {
        background: transparent;
    }

    /* A transparent border plus `background-clip: content-box` is how a thumb
       gets padding: the track stays 10px, the thumb reads as 4px, and the
       hit area is still the whole gutter. */
    .wire-scroller::-webkit-scrollbar-thumb {
        background-color: rgb(203 213 225);
        background-clip: content-box;
        border: 3px solid transparent;
        border-radius: 9999px;
    }

    .wire-scroller::-webkit-scrollbar-thumb:hover {
        background-color: rgb(148 163 184);
    }

    .dark .wire-scroller::-webkit-scrollbar-thumb {
        background-color: rgb(71 85 105);
    }

    .dark .wire-scroller::-webkit-scrollbar-thumb:hover {
        background-color: rgb(100 116 139);
    }
</style>
@endassets
