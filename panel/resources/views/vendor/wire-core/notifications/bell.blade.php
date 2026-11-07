{{-- Notification bell: the mark, and a slide-over panel behind it.

     A panel rather than a 320px dropdown, and that is the whole change: an inbox
     is a place you go, not a menu you brush past. It has room for the message
     under its title, for day headings, for a tab that hides what has been read,
     and for a link to the full list when one is routed — none of which fits a
     dropdown that has to stay narrow enough not to cover the page it hangs off.

     ─── The mark has three states, not two ───────────────────────

     A bell with no badge cannot say whether nothing has ever happened or whether
     the user has read everything, and those are different things to be told:

       nothing at all   → a plain bell
       all read         → a quiet grey dot: something is in there, nothing is waiting
       unread           → the count, and it is the only thing here that is loud

     The count sits in a ring the colour of the surface behind it, so it reads as
     a chip attached to the bell rather than a blob overlapping it — which is
     what "subtle" buys you at this size: the shape is legible at 16px because
     the ring separates it, not because the colour shouts. --}}
<div
    class="wire-notification-bell relative"
    @if($channel)
    x-data="wireNotificationLive({ channel: @js($channel) })"
    @endif
>
    @if($channel)
        @include('wire-core::notifications.partials.live-assets')
    @endif

    <button
        type="button"
        wire:click="$set('panelOpen', true)"
        data-testid="notification-bell" @wireEl('notification-bell')
        aria-haspopup="dialog"
        @class([
            'relative inline-flex items-center rounded-md p-2 transition-colors',
            'text-gray-500 hover:text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-700/50',
        ])
    >
        {!! icon('outline:bell', 'w-5 h-5') !!}

        {{-- The accessible name carries the count, because the badge is a
             decoration to a screen reader and "Notifications" alone loses the
             one thing the bell is there to say. --}}
        <span class="sr-only">
            {{ __('wire-core::messages.notifications') }}@if($unreadCount > 0) — {{ $unreadCount }}@endif
        </span>

        @if($unreadCount > 0)
            {{-- aria-hidden: the count is already in the button's name above, and
                 a screen reader reading both says the number twice.

                 `red`, not `danger`. `HasColor` already decided that danger MEANS
                 red and keeps its class strings literal so Tailwind's scanner
                 sees them; `bg-danger-500` in a template invents a token no
                 application defines, and an undefined utility is not a class —
                 the badge came out transparent with white text on white. Visible
                 in the markup, invisible on the screen, and nothing reports it.
                 `primary` is the one name deliberately left to the application
                 to define, and the installer writes that one. --}}
            <span
                aria-hidden="true"
                data-testid="notification-bell-count" @wireEl('notification-bell-count')
                class="absolute -top-0.5 -right-0.5 inline-flex min-w-[1.125rem] items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-[1.125rem] text-white ring-2 ring-white dark:ring-gray-800"
            >{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @elseif($hasAny)
            {{-- Read, but not empty. A dot rather than a zero: the bell's job is
                 to say what is waiting, and "0" is a number nobody needs. --}}
            <span
                aria-hidden="true"
                data-testid="notification-bell-dot" @wireEl('notification-bell-dot')
                class="absolute top-0.5 right-0.5 h-1.5 w-1.5 rounded-full bg-gray-300 ring-2 ring-white dark:bg-gray-600 dark:ring-gray-800"
            ></span>
        @endif
    </button>

    {{-- Rule 5: the Htmlable object, not <x-wire-modals::slide-over>. --}}
    {{ new \NyonCode\WireCore\Modals\Html\SlideOver(
        heading: __('wire-core::messages.notifications'),
        stickyHeader: true,
        stickyFooter: true,
        bottomSheetOnMobile: true,
        id: 'wire-notifications',
        wireModel: 'panelOpen',
        bodyView: 'wire-core::notifications.partials.panel',
        bodyData: ['groups' => $groups, 'tab' => $tab, 'unreadCount' => $unreadCount],
        footerView: 'wire-core::notifications.partials.panel-footer',
        footerData: ['indexUrl' => $indexUrl, 'unreadCount' => $unreadCount, 'hasRead' => $hasRead],
    ) }}
</div>
