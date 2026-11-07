{{-- Group header row — rendered ONCE per table, not once per group.

     Variables: $label (already escaped for text content), $colSpan, $cellPadding.

     Everything but the label is table-static, so the Table compiles this into a
     one-slot skeleton and each group splices its own label
     ({@see Table::getGroupHeaderRow()}). On a table grouped by something
     high-cardinality — a date, an invoice number — there is one group per row, and
     this was a view render and six DOM text nodes for each of them.

     Tags touch: a whitespace run between two tags is one text node the morph walks. --}}
<tr class="bg-gray-100/80 dark:bg-gray-800/80 border-t border-gray-200 dark:border-gray-700"><td colspan="{{ $colSpan }}" class="{{ $cellPadding }} py-2"><span class="text-sm font-semibold text-gray-900 dark:text-white">{!! $label !!}</span></td></tr>
