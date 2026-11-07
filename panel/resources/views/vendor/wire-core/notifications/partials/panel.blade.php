{{-- The panel's body: two tabs, then the list under day headings.

     Every branch here is on presentation state the component already resolved —
     which tab is active, which day a run of rows belongs to, whether a row is
     read, where it goes, what can be done about it. The payload was read, the
     icon chosen, the destination looked up, the actions restored and the rows
     grouped in PHP ({@see NotificationBell::items()}), so nothing below asks a
     question about the domain. --}}
<div class="-mx-4 -my-4 sm:-mx-6" wire:loading.class="opacity-60" wire:target="setTab,markAsRead,markAsUnread,markAllAsRead,delete,clearRead">
    {{-- Tabs. Two `wire:click`s rather than a filter control: there are exactly
         two, they are the whole vocabulary of an inbox, and a select would put a
         second interaction in front of the commonest one.

         The pills are taller below `sm` — a tab is the first thing a thumb goes
         for, and a 24px one is a miss waiting to happen. --}}
    <div class="flex items-center gap-1 border-b border-gray-100 px-4 py-3 sm:px-6 dark:border-gray-700" role="tablist">
        <button
            type="button"
            role="tab"
            wire:click="setTab('all')"
            data-testid="notification-tab-all" @wireEl('notification-tab-all')
            aria-selected="{{ $tab === 'all' ? 'true' : 'false' }}"
            @class([
                'rounded-md px-3 py-2 text-xs font-medium transition sm:px-2.5 sm:py-1',
                'bg-gray-100 text-gray-900 dark:bg-gray-700 dark:text-white' => $tab === 'all',
                'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== 'all',
            ])
        >{{ __('wire-core::messages.notifications_all') }}</button>

        <button
            type="button"
            role="tab"
            wire:click="setTab('unread')"
            data-testid="notification-tab-unread" @wireEl('notification-tab-unread')
            aria-selected="{{ $tab === 'unread' ? 'true' : 'false' }}"
            @class([
                'inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-xs font-medium transition sm:px-2.5 sm:py-1',
                'bg-gray-100 text-gray-900 dark:bg-gray-700 dark:text-white' => $tab === 'unread',
                'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== 'unread',
            ])
        >
            {{ __('wire-core::messages.notifications_unread') }}

            @if($unreadCount > 0)
                <span class="rounded-full bg-red-600 px-1.5 text-[10px] font-semibold leading-4 text-white">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
            @endif
        </button>
    </div>

    @forelse($groups as $heading => $rows)
        {{-- A run of rows under the day they landed on. Sticky, so the heading
             stays legible while its own run scrolls past — which is the whole
             value of having one. --}}
        <p class="sticky top-0 z-10 bg-gray-50/95 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 backdrop-blur sm:px-6 dark:bg-gray-900/95 dark:text-gray-400">
            {{ $heading }}
        </p>

        <div class="divide-y divide-gray-100 dark:divide-gray-700/50">
            @foreach($rows as $item)
                @include('wire-core::notifications.partials.panel-row', ['item' => $item])
            @endforeach
        </div>
    @empty
        <div class="px-4 py-12 sm:px-6">
            @include('wire-core::partials.empty-state', [
                'icon' => $tab === 'unread' ? 'outline:check-circle' : 'outline:bell-slash',
                'heading' => $tab === 'unread'
                    ? __('wire-core::messages.notifications_all_read')
                    : __('wire-core::messages.no_notifications'),
                'description' => $tab === 'unread' ? __('wire-core::messages.no_unread_notifications') : null,
            ])
        </div>
    @endforelse
</div>
