{{-- The PageChrome registration point for the replay entry.

     The component is mounted only when a tour claims this screen — the same
     shape as the host beside it, and for the same reason. A Livewire component
     is a snapshot and a checksum on every page that renders it, and an
     application with no tours should pay neither. What it pays instead is this
     `@if` and one scan of an empty registry.

     Deciding here rather than inside the component also keeps the component
     honest: it is handed the id it works on, so it never has to ask the router
     a question that only the page render can answer. --}}
@if ($replayableTour !== null)
    @livewire('wire-tour-replay', ['tourId' => $replayableTour->getId()], key('wire-tour-replay-'.$replayableTour->getId()))
@endif
