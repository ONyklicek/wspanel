@php
    use NyonCode\WireCore\Foundation\Support\ResponsiveGrid;

    /** @var array<int, mixed> $components */
    /** @var int $columns */

    // The field ladder, from its one owner — see the note in
    // schema/step.blade.php. Each entry is told it, so its own span is drawn
    // against the columns this panel actually has at each width.
    $ladder = ResponsiveGrid::fieldColumns($columns);
@endphp

<div class="wire-panel grid gap-4 {{ ResponsiveGrid::cols($ladder) }}">
    @foreach($components as $component)
        @if($component->isVisible())
            {{ $component->inGridOf($ladder) }}
        @endif
    @endforeach
</div>
