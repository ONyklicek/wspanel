{{--
    MetricColumn cell — the figure, with its trend beside it.

    Variables: $value (formatted, already prepared), $isHtml, $textClasses,
    and the sparkline's $viewBox / $points / $strokeClass from
    Foundation\View\Sparkline. No arithmetic here: the geometry is resolved in
    PHP so it can be tested and so a widget can draw the same curve.

    The curve sits before the number and shrinks first: a metric is read for its
    figure, and the trend is context. `aria-hidden` because the polyline says
    nothing a screen reader can use that the number does not already say.
--}}
<span class="inline-flex items-center justify-end gap-2 {{ $textClasses }}">
    <svg viewBox="{{ $viewBox }}" class="h-4 w-12 min-w-0 shrink" preserveAspectRatio="none" aria-hidden="true">
        <polyline fill="none" stroke="currentColor" stroke-width="1.5" class="{{ $strokeClass }}" points="{{ $points }}"/>
    </svg>
    <span class="shrink-0">@if($isHtml ?? false){!! $value !!}@else{{ $value }}@endif</span>
</span>
