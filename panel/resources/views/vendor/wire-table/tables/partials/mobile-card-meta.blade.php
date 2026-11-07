{{--
    One meta chip on a stacked card — compiled once and filled per record
    ({@see Table::getMobileCardMetaSkeleton()}, spliced by Support\CardRenderer).

    $content is the column's own rendered cell, so it arrives as markup.
--}}
<span>{!! $content !!}</span>
