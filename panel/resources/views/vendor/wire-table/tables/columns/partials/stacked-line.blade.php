{{--
    One line of a StackedColumn cell.

    Compiled once per column and filled per line ({@see StackedColumn::getLinesHtml()}),
    so a stack of three lines over fifty rows renders this once and splices it a
    hundred and fifty times. Both slots arrive escaped — the class is author-supplied
    and the value is record state.
--}}
<p class="{!! $class !!}">{!! $value !!}</p>
