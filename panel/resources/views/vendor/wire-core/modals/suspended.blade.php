{{-- A parent action modal stacked behind the active one.

     Unlike the active (top) modal, this frame is rendered click-inert (`inert`
     + dimmed) so only the top modal is interactive — but it stays a LIVE form:
     its fields are still bound to the frame's own depth-scoped state path
     (`…actions.{depth}.data.*`), so Livewire keeps re-rendering it. That is what
     makes a nested modal's `$setParent(...)` write show up immediately in the
     parent behind it, and lets the parent resume with the user's data intact
     when the modal above it closes.

     It deliberately paints NO backdrop of its own: the active modal on top
     already draws a single full-viewport scrim over everything beneath it, so
     stacking N of these does not compound into a near-black overlay.
     `$depthBelowTop` is how many levels this shell sits below the active modal
     (1 = directly beneath it).

     This frame is effectively INVISIBLE — a live state holder, not a visual
     "stacked cards" cue. Measured at depth 1 (headless Chrome, pixel diff of the
     page against the same frame hidden): it changes the rendering by at most
     9/255 per channel. Two independent causes, which would have to be fixed
     together:

       1. The active modal's panel covers it outright. Both frames share a width
          class and are centre-aligned, so the `scale()` below leaves this card
          narrower AND shorter than the modal in front of it — measured 483..917,
          wholly inside the child's 478..926.
       2. Even where it peeks out, the scrim washes it away. That scrim is a
          child of the active modal's own container (z-index 50 + depth·10), i.e.
          ABOVE this frame, and at `bg-gray-500/75` it passes only 25% through.
          Restyling this card cannot beat that: adding a 2px dark ring does not
          move the delta ceiling at all (33 either way), while removing the scrim
          jumps it to 138 — the scrim is the binding constraint, not the colour.

     Making the cue real means lifting the scrim out of the modal container into
     its own layer *below* the parent frames AND letting this card grow past the
     child — a change to every modal surface for a decorative effect. Not done on
     purpose. Do not "tune" the numbers below expecting the parent to appear.

     Expects:
       - $modalData:      resolved modal config (heading/description/width/slideOver)
       - $formInstance:   the frame's live Form instance (or null → heading only)
       - $zIndex:         the stacking z-index for this depth (below the active modal)
       - $depthBelowTop:  levels below the active modal (>= 1) --}}
@php
    use NyonCode\WireCore\Foundation\Concerns\HasModalProperties;

    $isSlideOver = (bool) ($modalData['slideOver'] ?? false);
    $widthClass = HasModalProperties::getMaxWidthClass($modalData['width'] ?? 'md');
    $formInstance = $formInstance ?? null;

    // Recede each deeper level a little more, clamped so a deep stack never
    // scales away to nothing. Kept as intent — it costs nothing — but it does
    // not actually render: see the invisibility note above before touching it.
    $depth = max(1, (int) ($depthBelowTop ?? 1));
    $scale = max(0.9, 1 - $depth * 0.03);
    $lift = min(24, $depth * 8);
@endphp

<template x-teleport="body" wire:key="wire-modal-suspended-{{ $depthBelowTop ?? 0 }}">
    <div
        class="fixed inset-0 overflow-hidden pointer-events-none"
        style="z-index: {{ $zIndex }};"
        aria-hidden="true"
        inert
    >
        @if($isSlideOver)
            <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10">
                <div
                    class="w-screen {{ $widthClass }} opacity-70"
                    style="transform: translateX({{ $lift }}px);"
                >
                    <div class="flex h-full flex-col overflow-y-auto bg-white dark:bg-gray-800 shadow-2xl">
                        <div class="px-4 pt-6 sm:px-6">
                            @if(! empty($modalData['heading']))
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $modalData['heading'] }}</h3>
                            @endif
                            @if(! empty($modalData['description']))
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $modalData['description'] }}</p>
                            @endif
                        </div>
                        @if($formInstance)
                            <div class="px-4 py-5 sm:px-6">{{ $formInstance }}</div>
                        @endif
                    </div>
                </div>
            </div>
        @else
            <div class="flex min-h-screen items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div
                    class="relative inline-block w-full transform overflow-hidden rounded-2xl bg-white dark:bg-gray-800 text-left align-bottom shadow-xl sm:align-middle opacity-70 {{ $widthClass }}"
                    style="transform: translateY(-{{ $lift }}px) scale({{ $scale }});"
                >
                    <div class="px-4 pt-5 sm:px-6 sm:pt-6 pb-5">
                        @if(! empty($modalData['heading']))
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $modalData['heading'] }}</h3>
                        @endif
                        @if(! empty($modalData['description']))
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $modalData['description'] }}</p>
                        @endif
                        @if($formInstance)
                            <div class="mt-4">{{ $formInstance }}</div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</template>
