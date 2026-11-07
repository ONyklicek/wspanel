{{-- Canonical drag-handle markup. Owned in Blade (rendered by the
     Table::getDragHandleHtml() macro) and injected into the sortable Alpine
     component as config, so the handle SVG is never hand-built as a JS string.

     The grip itself comes from the canonical icon owner — it is registered as
     `sortable-grip` from resources/icons/grip.svg, so it is themeable and
     overridable like every other icon rather than being an inline <svg> here.
     Its size is given as width/height attributes rather than Tailwind classes:
     the handle's own CSS ships with this package (partials/scripts) and must not
     depend on the consumer's Tailwind build. --}}
<div class="wire-sortable-handle" data-testid="sortable-handle" @wireEl('sortable-handle') role="button" aria-label="Reorder">
    {!! icon('sortable-grip', '', '', '', ['width' => '16', 'height' => '16']) !!}
</div>
