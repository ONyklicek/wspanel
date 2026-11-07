{{-- The server→client channel of an inline-editable cell. --}}
{{-- Variables: $serverValue (string), $recordVersion (string) --}}
{{--
    An editable cell mounts with `wire:ignore.self` so a Livewire morph cannot
    stomp the optimistic state it is holding mid-edit. Livewire honours that by
    skipping the element's OWN attributes — children are still morphed — so
    anything the server needs to keep telling the cell has to hang off a child.

    That is this node, and it is the whole reason a cell can ever show a value it
    did not write itself. With these two attributes on the ignored root (where
    they used to live) they held whatever the FIRST render put there, for the
    lifetime of the page: no re-render, no poll tick and no modal write could put
    a newer value on screen, and the lock version the cell kept sending was the
    one the page loaded with — so the user's own next edit came back refused as
    somebody else's.

    The pair moves together or not at all. `wireEditableCell`'s MutationObserver
    watches both attributes and, when either changes, re-reads the value from
    here; a version updated next to a stale value would "reconcile" the cell back
    to what the page loaded with a moment after a successful save.
--}}
<span hidden
      data-cell-sync
      data-server-value="{{ $serverValue }}"
      data-record-version="{{ $recordVersion }}"
></span>
