{{-- One action button on a stored notification.

     Two shapes, because an action has two ways of meaning something and only one
     of them survives being stored: a **link** goes somewhere and still works
     three days later, while an **event** reaches a Livewire listener that has to
     be on the page. Both are rendered — an application whose listener is mounted
     is entitled to its event — and the link is what a queued job should reach
     for. See NotificationAction.

     The colour was resolved in PHP by NotificationStyle, which owns the same six
     names the toast container knows: an action written once must not mean one
     thing in a toast and something else in the panel. --}}
@if(! empty($action['url']))
    <a
        href="{{ $action['url'] }}"
        @wireNavigate($action['url'])
        data-testid="notification-action" @wireEl('notification-action')
        class="{{ $action['classes'] }}"
    >{{ $action['label'] }}</a>
@elseif(! empty($action['event']))
    {{-- Dispatched from the browser, exactly as the toast container dispatches
         the same action: the listener is a host component's `#[On]`, and which
         component that is is the application's business, not the bell's. --}}
    <button
        type="button"
        x-on:click="window.Livewire.dispatch(@js($action['event']), @js($action['payload'] ?? []))"
        data-testid="notification-action" @wireEl('notification-action')
        class="{{ $action['classes'] }}"
    >{{ $action['label'] }}</button>
@endif
