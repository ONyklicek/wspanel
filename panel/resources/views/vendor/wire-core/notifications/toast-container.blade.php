@php
    use NyonCode\WireCore\Foundation\View\Palette;

    // The toast's types are palette roles under this surface's own names:
    // `error` is what a notification calls `danger`. Resolved in PHP so an application
    // that re-points a role moves the toast with everything else — the map is
    // static per request, so Alpine still receives plain strings.
    $toastRoles = [
        'success' => 'success',
        'error' => 'danger',
        'warning' => 'warning',
        'info' => 'info',
        'primary' => 'primary',
        'gray' => 'gray',
    ];
    $toastAccent = array_map(Palette::getModalIconTextClass(...), $toastRoles);
    $toastBar = array_map(Palette::getAccentBgClass(...), $toastRoles);
@endphp
{{-- Toast Notification Container --}}
{{-- Listens for Livewire events and renders toast notifications with a per-card --}}
{{-- countdown bar, hover-to-pause, action buttons, an optional collapsible stack, --}}
{{-- a max-visible overflow cap, and reduced-motion / screen-reader support. --}}
<div
    x-data="{
        toasts: [],
        hovered: false,
        showAll: false,
        reduceMotion: false,
        stack: @js($stack),
        progress: @js($progress),
        topAnchored: @js($topAnchored()),
        flashed: @js($flashed()),
        defaultDuration: {{ $duration }},
        max: {{ $max }},
        raf: null,
        lastTs: null,

        get expanded() {
            // A stacked pile fans out while hovered; reduced motion never collapses.
            return this.reduceMotion || ! this.stack || this.hovered;
        },
        get paused() {
            // Hovering freezes every countdown (and the auto-dismiss with it).
            return this.hovered;
        },

        add(toast) {
            const id = Date.now() + Math.random();
            const raw = toast.duration;
            const duration = (raw === null || raw === undefined)
                ? this.defaultDuration
                : Number(raw);
            const sticky = ! (duration > 0);
            this.toasts.push({ ...toast, id, duration, remaining: duration, sticky });
            this.ensureLoop();
        },
        remove(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
            if (this.max > 0 && this.toasts.length <= this.max) this.showAll = false;
        },

        // Newest first: closest to the anchor edge and on top of the pile.
        renderList() {
            return [...this.toasts].reverse();
        },
        // Cap to `max` newest toasts unless the overflow has been expanded.
        visibleList() {
            const list = this.renderList();
            if (this.max > 0 && ! this.showAll && list.length > this.max) {
                return list.slice(0, this.max);
            }
            return list;
        },
        hiddenCount() {
            return Math.max(0, this.toasts.length - this.visibleList().length);
        },

        // Fraction of life remaining, for the countdown bar width.
        barWidth(toast) {
            if (toast.sticky || ! toast.duration) return 0;
            return Math.max(0, Math.min(100, (toast.remaining / toast.duration) * 100));
        },
        // Per-card transform for the collapsed pile (0 = front, at the anchor).
        cardStyle(depth) {
            if (! this.stack || this.expanded) return '';
            const sign = this.topAnchored ? 1 : -1;
            const offset = depth * 14 * sign;
            const scale = Math.max(1 - depth * 0.05, 0.9);
            const opacity = depth > 2 ? 0 : Math.max(1 - depth * 0.2, 0);
            const events = depth === 0 ? 'auto' : 'none';
            return `grid-area:1/1;transform:translateY(${offset}px) scale(${scale});opacity:${opacity};z-index:${100 - depth};pointer-events:${events};`;
        },
        // Semantic accent for an action button (falls back to the toast type).
        actionColor(action, toast) {
            const map = @js($toastAccent);
            return map[action.color] ?? map[toast.type] ?? 'text-gray-900 dark:text-white';
        },
        handleAction(toast, action) {
            if (action.event && window.Livewire) {
                window.Livewire.dispatch(action.event, action.payload || {});
            }
            if (action.close !== false) this.remove(toast.id);
        },

        showFlashed() {
            // The toast the browser event could not deliver: its request
            // redirected, so the document that would have shown it was replaced.
            // Livewire drops a flash left by an update that did not redirect, so
            // anything arriving here crossed a navigation on purpose.
            if (! this.flashed) return;

            // Once per server render. A wire:navigate back button restores a
            // cached page and re-runs this init over the same markup, and the
            // id is what tells that apart from a genuinely new notification.
            // A list rather than the last id alone, because back and forward
            // reach further than one page.
            const key = 'wire-toast-flashed';
            try {
                const stored = JSON.parse(window.sessionStorage.getItem(key) ?? '[]');
                const shown = Array.isArray(stored) ? stored : [];
                if (shown.includes(this.flashed.id)) return;
                window.sessionStorage.setItem(key, JSON.stringify([...shown, this.flashed.id].slice(-20)));
            } catch (e) {
                // Storage can be unavailable, full, or holding something else
                // (private windows, blocked site data). Showing the toast is the
                // better failure.
            }

            this.add(this.flashed.payload);
        },

        ensureLoop() {
            if (this.raf !== null) return;
            this.lastTs = null;
            this.raf = requestAnimationFrame(this.loop.bind(this));
        },
        loop(ts) {
            if (this.lastTs === null) this.lastTs = ts;
            const dt = ts - this.lastTs;
            this.lastTs = ts;

            if (! this.paused) {
                for (const t of this.toasts) {
                    if (t.sticky) continue;
                    t.remaining = Math.max(0, t.remaining - dt);
                }
                this.toasts
                    .filter(t => ! t.sticky && t.remaining <= 0)
                    .forEach(t => this.remove(t.id));
            }

            if (this.toasts.length === 0) {
                this.raf = null;
                return;
            }
            this.raf = requestAnimationFrame(this.loop.bind(this));
        },

        init() {
            this.reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false;
            this.showFlashed();
            const eventName = @js($eventName);
            const dispatch = (payload) => window.dispatchEvent(
                new CustomEvent(eventName, { detail: payload })
            );
            const normalize = (type, message, options) => {
                const data = (message !== null && typeof message === 'object')
                    ? { ...message }
                    : { message, ...(options || {}) };
                if (type && !data.type) data.type = type;
                if (!data.type) data.type = 'info';
                return data;
            };
            if (!window.wireToast) {
                const toast = (message, options) => dispatch(normalize(null, message, options));
                ['success', 'error', 'warning', 'info'].forEach((type) => {
                    toast[type] = (message, options) => dispatch(normalize(type, message, options));
                });
                window.wireToast = toast;
            }
            if (window.Alpine && !window.Alpine.__wireToastMagic) {
                window.Alpine.__wireToastMagic = true;
                window.Alpine.magic('toast', () => window.wireToast);
            }
        }
    }"
    x-on:{{ $eventName }}.window="add($event.detail)"
    class="fixed z-[99] {{ $positionClasses() }} pointer-events-none"
    style="width: 24rem; max-width: calc(100vw - 2rem);"
>
    <div
        @mouseenter="hovered = true"
        @mouseleave="hovered = false"
        role="status"
        aria-live="polite"
        aria-atomic="false"
        class="pointer-events-auto"
        :class="stack && ! expanded
            ? 'grid'
            : (topAnchored ? 'flex flex-col gap-2' : 'flex flex-col-reverse gap-2')"
    >
        <template x-for="(toast, index) in visibleList()" :key="toast.id">
            <div
                x-show="true"
                x-transition:enter="transform ease-out duration-300 transition motion-reduce:transition-none"
                x-transition:enter-start="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
                x-transition:enter-end="translate-y-0 opacity-100 sm:translate-x-0"
                x-transition:leave="transition ease-in duration-200 motion-reduce:transition-none"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                :style="cardStyle(index)"
                :role="toast.type === 'error' ? 'alert' : 'status'"
                class="w-full overflow-hidden rounded-xl bg-white dark:bg-gray-800 shadow-lg ring-1 ring-black/5 dark:ring-white/10 transition-[transform,opacity] duration-300 ease-out motion-reduce:transition-none"
            >
                <div class="p-4">
                    <div class="flex items-start gap-3">
                        {{-- Icon --}}
                        <div class="flex-shrink-0">
                            <template x-if="toast.type === 'success'">
                                {!! icon('outline:check-circle', 'h-5 w-5', $toastAccent['success']) !!}
                            </template>
                            <template x-if="toast.type === 'error'">
                                {!! icon('outline:exclamation-circle', 'h-5 w-5', $toastAccent['error']) !!}
                            </template>
                            <template x-if="toast.type === 'warning'">
                                {!! icon('outline:exclamation-triangle', 'h-5 w-5', $toastAccent['warning']) !!}
                            </template>
                            <template x-if="toast.type === 'info'">
                                {!! icon('outline:information-circle', 'h-5 w-5', $toastAccent['info']) !!}
                            </template>
                        </div>

                        {{-- Content --}}
                        <div class="flex-1 min-w-0">
                            <p x-show="toast.title" x-text="toast.title" class="text-sm font-semibold text-gray-900 dark:text-white"></p>
                            <p x-text="toast.message" class="text-sm text-gray-600 dark:text-gray-300" :class="{ 'mt-1': toast.title }"></p>

                            {{-- Action buttons --}}
                            <template x-if="toast.actions && toast.actions.length">
                                <div class="mt-2 flex flex-wrap items-center gap-4">
                                    <template x-for="(action, ai) in toast.actions" :key="ai">
                                        <button
                                            type="button"
                                            @click="handleAction(toast, action)"
                                            :data-testid="'toast-action-' + ai"
                                            class="text-sm font-semibold hover:opacity-80 focus:outline-none focus-visible:underline"
                                            :class="actionColor(action, toast)"
                                            x-text="action.label"
                                        ></button>
                                    </template>
                                </div>
                            </template>
                        </div>

                        {{-- Close button --}}
                        <button
                            type="button"
                            @click="remove(toast.id)"
                            data-testid="toast-dismiss" @wireEl('toast-dismiss')
                            aria-label="{{ __('Close') }}"
                            class="flex-shrink-0 rounded-lg p-1 text-gray-500 hover:text-gray-500 dark:hover:text-gray-300 focus:outline-none"
                        >
                            {!! icon('outline:x-mark', 'h-4 w-4') !!}
                            <span class="sr-only">{{ __('Close') }}</span>
                        </button>
                    </div>
                </div>

                {{-- Countdown bar --}}
                <template x-if="progress && ! toast.sticky">
                    <div class="h-1 w-full bg-gray-100 dark:bg-white/5">
                        <div
                            class="h-full"
                            :class="@js($toastBar)[toast.type] ?? ''"
                            :style="`width:${barWidth(toast)}%`"
                        ></div>
                    </div>
                </template>
            </div>
        </template>

        {{-- Overflow indicator: reveals the hidden (oldest) toasts on click --}}
        <template x-if="hiddenCount() > 0">
            <button
                type="button"
                @click="showAll = true"
                data-testid="toast-expand" @wireEl('toast-expand')
                class="pointer-events-auto self-center rounded-full bg-gray-900/80 px-3 py-1 text-xs font-medium text-white shadow-lg backdrop-blur hover:bg-gray-900 focus:outline-none dark:bg-white/15 dark:hover:bg-white/25"
                x-text="'+' + hiddenCount() + ' {{ __('more') }}'"
            ></button>
        </template>
    </div>
</div>
