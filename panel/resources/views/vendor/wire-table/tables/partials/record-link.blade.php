{{--
    The link a record url puts around a cell.

    One partial for both surfaces: the desktop row wraps every non-editable cell
    in it ({@see Support\RowRenderer}) and the stacked card wraps its title
    ({@see Support\CardRenderer}), so the affordance has one source instead of
    two copies that drift.

    Compiled ONCE per table ({@see Table::getRecordLinkSkeleton()}) and filled per
    record: $url arrives already escaped for the attribute, $content as the cell's
    own rendered markup. $navigate is not a slot but a shape — the table compiles
    one skeleton per value ({@see \NyonCode\WireCore\Foundation\Routing\ClientNavigation}).

    Mind the whitespace: this wraps a cell inline, so the tags touch. A run of
    whitespace between two tags is a DOM text node the morph walks — here it would
    also put a space inside the link text.
--}}
<a href="{!! $url !!}"{!! $navigate ? ' wire:navigate' : '' !!} class="hover:text-primary-600 dark:hover:text-primary-400">{!! $content !!}</a>
