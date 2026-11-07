{{--
    One label/value pair in a stacked card's detail grid — compiled once and
    filled per record ({@see Table::getMobileCardDetailSkeleton()}, spliced by
    Support\CardRenderer).

    $spanClass is the grid span: an odd last item takes both columns rather than
    leaving a hole beside it. It is a value, not a shape, so it is a slot and not
    a second compiled skeleton. $label arrives escaped, $content as the column's
    rendered cell.

    Mind the whitespace: the tags touch, and a <dt>/<dd> pair is emitted once per
    detail per card.
--}}
<div class="{!! $spanClass !!}"><dt class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-0.5">{!! $label !!}</dt><dd class="text-sm text-gray-900 dark:text-white">{!! $content !!}</dd></div>
