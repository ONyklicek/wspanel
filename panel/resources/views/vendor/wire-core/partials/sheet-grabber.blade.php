@php
    use NyonCode\WireCore\Foundation\Support\MobileSheet;

    /**
     * Drag handle ("grabber") for a mobile bottom sheet. Must be rendered as the
     * FIRST child of the sheet panel — the x-sheet-dismiss directive drags the
     * grabber's parent (the panel). Shown only below the configured breakpoint;
     * from there up the panel is a floating dropdown and the handle is hidden.
     *
     * @var string      $dismiss     Alpine expression that closes the panel.
     * @var string|null $breakpoint  Sheet breakpoint override (sm|md|lg).
     */
    $dismiss ??= 'close()';
    $breakpoint ??= null;
@endphp
<div
    x-sheet-dismiss="{{ $dismiss }}"
    data-sheet-bp="{{ MobileSheet::px($breakpoint) }}"
    class="{{ MobileSheet::grabberShow($breakpoint) }} justify-center pt-2.5 pb-1 touch-none select-none"
    aria-hidden="true"
>
    <span class="h-1.5 w-10 rounded-full bg-gray-300 dark:bg-gray-600"></span>
</div>
