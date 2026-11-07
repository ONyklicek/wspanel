{{-- The guided walkthrough, mounted once per page by whatever draws the chrome.

     Registered into NyonCode\WireCore\Foundation\View\PageChrome by this
     package's service provider. An application that has registered no tour
     renders nothing here at all — see the `@if` below, which is where that is
     decided, and why it is decided there rather than at boot.

     ## No JavaScript ships for this

     Everything below is plain Alpine over data PHP resolved. The two things a
     tour popup is really made of are already on every page inside
     `wire-core-dropdown.js`, which is a declared bundle:

       $float(reference, floating, config)  — Floating UI with flip/shift/size,
                                              a z-index resolver that clears
                                              modals, and re-assertion after a
                                              Livewire morph strips inline style

       modals.partials.focus-trap           — expression-only, no bundle at all

     So this feature adds no entry to `build:core-assets`, no committed dist
     file and no bytes to any <head>.

     ## What it will not do

     **Below the sheet breakpoint the panel docks to the bottom of the screen**
     instead of floating beside its element. It used not to run there at all —
     the objection was that a sheet cannot point at anything — and that is
     answered rather than ignored: the highlight ring stays on the element and
     the page scrolls it into the room above the panel, so a phone gets a tour
     that points, not a stack of captions. An element a phone does not show (the
     sidebar is a drawer there) is skipped by the rule below, and the counter
     says the tour got shorter.

     **Every step scrolls its element into view**, on any screen: an element
     under the fold used to be pointed at from off-screen.

     **A step may be on another page** (`TourStep::on()`). Reaching it navigates
     there with the tour's id and the step in the query, the server renders the
     host for that page, and the tour carries on. The browser never knows a
     route — each step arrives with its page's URL, from the one owner of those.

     **A step whose element is missing or hidden is skipped, never fatal.** An
     application may have hidden a control, this screen may legitimately not
     have it, or it may be rendered and waiting behind an `x-show`. The
     worst outcome of an upgrade is therefore a tour that got shorter. That is
     also why a step names an element *hook* and not a selector: a typo would
     otherwise be indistinguishable from an absent element, which is why
     TourStep validates the name at definition time instead.

     ## Traps this file is written around

     - `$refs` are NOT populated when a parent's `x-init` runs, and the throw
       takes the whole region with it. Every first read of `$refs.panel` is
       inside `$nextTick`.
     - Single quotes throughout the Alpine expressions: a double quote inside an
       attribute truncates it, and this file would stop working silently.
     - Blade directives sit on their own lines inside an opening tag. A
       directive butted against a word character does not compile. --}}
{{-- Nothing to run is the overwhelmingly common answer — no tour registered, or
     none that claims this screen, or one this person already acknowledged — and
     it costs an empty `foreach` over the registry and this `@if`. The chrome
     itself is registered unconditionally, because provider order is composer's
     discovery order and an application registering its tours in its own
     provider may boot after this package. --}}
@if ($tour !== null)
@php($payload = $tourHost->payload($tour))
{{-- The round trip, outside the `wire:ignore` below. A component cannot both be
     ignored by Livewire and call it, and the chrome needs the ignore to keep the
     inline styles Floating UI writes onto the panel. --}}
@livewire('wire-tour-acknowledgement', ['tourId' => $tour->getId()], key('wire-tour-'.$tour->getId()))
<div
    x-data="{
        tourId: @js($tour->getId()),
        steps: @js($payload['steps']),
        breakpoint: @js($payload['breakpoint']),
        resume: @js($payload['resume']),
        from: @js($payload['from']),
        welcome: @js($payload['welcome']),
        reported: null,
        progressTemplate: @js(__('wire-core::messages.tour_progress', ['current' => '{c}', 'total' => '{t}'])),

        {{-- `plan` is the indices of the steps whose element was actually on the
             page when the tour started, and `cursor` walks it. The progress
             counter reads off the plan rather than off `steps`, which is the
             whole reason the plan exists: a tour with a step this screen does
             not have would otherwise count it and then skip it, and somebody
             would watch "1 of 3" become "3 of 3". A tour that got shorter should
             say it is shorter. --}}
        plan: [],
        cursor: 0,
        active: false,
        greeting: false,
        dock: false,
        detach: null,
        rect: { top: 0, left: 0, width: 0, height: 0 },
        raised: null,

        get step() { return this.steps[this.plan[this.cursor]] || {}; },
        get isLast() { return this.cursor >= this.plan.length - 1; },
        get progress() {
            return this.progressTemplate
                .replace('{c}', this.cursor + 1)
                .replace('{t}', this.plan.length);
        },

        {{-- Whether the panel docks to the bottom rather than floating beside
             its element. Re-read rather than cached: a tablet rotates, and a
             desktop window gets dragged narrow — which re-places the step
             instead of ending the tour. --}}
        docked() {
            return window.matchMedia('(max-width: ' + this.breakpoint + 'px)').matches;
        },

        {{-- On the page is not enough: it has to be *showing*. A great deal of
             this framework's markup is rendered and hidden with `x-show` — the
             bulk-action bar until rows are selected, a dropdown until it opens —
             and `querySelector` finds those just as happily. A step pointed at
             one would pin the panel to a box of zero size in the corner.
             `getClientRects()` is empty for anything `display: none` or
             detached, and it is the test the focus trap already uses for the
             same question. --}}
        shown(selector) {
            const el = document.querySelector(selector);
            if (! el || el.getClientRects().length === 0) { return null; }
            {{-- Rendered, displayed and pushed off the side is not showing
                 either: a phone's sidebar is a drawer translated out of view,
                 and a ring drawn around it would sit off the edge of the screen. --}}
            const box = el.getBoundingClientRect();
            return box.width > 0 && box.right > 0 && box.left < window.innerWidth ? el : null;
        },

        {{-- A step on this page is available when its element is showing; one
             on another page, when there is an address to go to. --}}
        available(i) {
            const step = this.steps[i];
            if (! step) { return false; }
            return step.here ? !! this.shown(step.selector) : !! step.url;
        },

        at(cursor) {
            const step = this.steps[this.plan[cursor]];
            return step && step.here ? this.shown(step.selector) : null;
        },

        {{-- The first planned step still on the page, walking in `direction`.
             Null when there is none left, which ends the tour. The plan is built
             once, but the page keeps moving underneath it — a row can be
             filtered away between one step and the next — so this re-checks
             rather than trusting the plan it walks. --}}
        seek(from, direction) {
            for (let i = from; i >= 0 && i < this.plan.length; i += direction) {
                if (this.available(this.plan[i])) { return i; }
            }
            return null;
        },

        {{-- Wait for the page to stop moving before deciding what is on it.
             A tour that plans the moment Alpine starts sees the page as it is
             *mid-transition*: a phone's sidebar is on screen until its own
             binding slides it away over 200 ms, so a step pointing into it was
             counted, shown, and pinned to a drawer that then left. What is
             awaited is the thing itself — the document loaded, two frames, and
             every finite animation running at that moment — rather than a
             guessed delay; the cap is only there so an animation that never
             settles cannot hold a tour back for ever. --}}
        settle() {
            const frames = () => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));
            const loaded = document.readyState === 'complete'
                ? Promise.resolve()
                : new Promise((done) => window.addEventListener('load', done, { once: true }));
            const moving = () => (document.getAnimations ? document.getAnimations() : [])
                .filter((a) => a.playState === 'running' && a.effect && a.effect.getTiming().iterations !== Infinity)
                .map((a) => a.finished.catch(() => null));
            const cap = new Promise((done) => setTimeout(done, 1200));
            return loaded.then(frames).then(() => Promise.race([Promise.all(moving()), cap])).then(frames);
        },

        start() {
            {{-- The address a tour arrived by is spent the moment it is read:
                 left in place, a reload or a bookmark would restart the
                 walkthrough mid-way. --}}
            const url = new URL(window.location.href);
            if (url.searchParams.has('wire-tour')) {
                url.searchParams.delete('wire-tour');
                url.searchParams.delete('wire-tour-step');
                window.history.replaceState(window.history.state, '', url.toString());
            }

            this.plan = this.steps
                .map((step, i) => (this.available(i) ? i : null))
                .filter((i) => i !== null);

            {{-- Carrying on, the page before already decided which steps this
                 tour has: a step it skipped (a phone did not show that element)
                 must stay skipped here, or the counter reads "2 of 4" on one
                 page and "5 of 5" on the next. Its plan rides in the tab's own
                 storage, narrowed again to what is available on this page. --}}
            if (this.resume !== null) {
                const carried = this.carriedPlan();
                if (carried) { this.plan = carried.filter((i) => this.available(i)); }
            }

            if (this.plan.length === 0) { return; }

            {{-- Carrying on from another page: at the step that was asked for,
                 or the first one after it still standing. With none left the
                 tour stops here rather than sending somebody back the way they
                 came; nothing is recorded, so it runs again on the next visit. --}}
            let cursor = 0;
            if (this.resume !== null) {
                cursor = this.plan.findIndex((i) => i >= this.resume);
                if (cursor === -1) { return; }
            }

            {{-- Coming back to a tour left halfway on an earlier visit: at the
                 step it was left on when that step is on this page, or else at
                 the last one before it here — so "Next" leads on to where they
                 were — or else the first one after it. --}}
            if (this.resume === null && this.from !== null) {
                const here = (i) => this.steps[i] && this.steps[i].here;
                const exact = this.plan.indexOf(this.from);
                const before = this.plan.map((i, at) => (i < this.from && here(i) ? at : -1)).filter((at) => at !== -1).pop();
                const after = this.plan.findIndex((i) => i > this.from && here(i));
                cursor = exact !== -1 && here(this.from) ? exact : (before !== undefined ? before : Math.max(after, 0));
            }

            this.cursor = cursor;

            {{-- The welcome block is the tour's first beat rather than a step of
                 it, so it is shown only when the tour is starting from the top.
                 Somebody carried here by "Next" from another page, or coming
                 back to a walkthrough they left halfway, has answered it
                 already and would be asked whether to start something they are
                 in the middle of. --}}
            if (this.welcome && this.resume === null && this.from === null) {
                this.greeting = true;
                return;
            }

            this.begin();
        },

        {{-- Past the greeting and into the walkthrough. Docked before shown:
             otherwise a phone gets one frame of the floating panel in the
             top-left corner before `place()` moves it. --}}
        begin() {
            this.greeting = false;
            this.dock = this.docked();
            this.active = true;
            this.$nextTick(() => this.place());
        },

        {{-- "Later", and the Escape that means the same thing.
             The count is the server's to keep: `TourState::postpone()` decides
             whether this one was the last, and acknowledges the tour when it
             was. A tour whose author allowed no postponement carries no `later`
             label, so dismissing its welcome records nothing at all and the
             tour greets again on the next visit — the same as walking away
             from a walkthrough, which has always been the free answer. --}}
        later() {
            this.greeting = false;
            if (! this.welcome || ! this.welcome.later) { return; }
            window.dispatchEvent(new CustomEvent('wire-tour:postponed', {
                detail: { id: this.tourId },
            }));
        },

        planKey() { return 'wire-tour-plan:' + this.tourId; },

        {{-- Storage can be switched off or full; a tour that could not carry
             its plan still carries on, it just recounts on the next page. --}}
        carriedPlan() {
            try {
                const plan = JSON.parse(window.sessionStorage.getItem(this.planKey()) || 'null');
                return Array.isArray(plan) && plan.every((i) => Number.isInteger(i)) ? plan : null;
            } catch (e) { return null; }
        },

        carryPlan() {
            try { window.sessionStorage.setItem(this.planKey(), JSON.stringify(this.plan)); } catch (e) {}
        },

        {{-- To the page the next step is on. Livewire's own navigation when the
             step says so (`wire-core.navigate`, decided server-side), so an
             application using `wire:navigate` keeps its single-page feel; a
             plain visit otherwise. --}}
        go(index) {
            const step = this.steps[index];
            this.carryPlan();
            this.release();
            const target = new URL(step.url, window.location.origin);
            target.searchParams.set('wire-tour', this.tourId);
            target.searchParams.set('wire-tour-step', index);
            this.active = false;
            if (step.navigate && window.Livewire && typeof window.Livewire.navigate === 'function') {
                window.Livewire.navigate(target.toString());
            } else {
                window.location.assign(target.toString());
            }
        },

        {{-- Bring the element into the room the panel leaves — below the sticky
             top bar, and above the panel itself when it is docked — and only if
             it is not already there, so a step on a visible element never
             jolts the page. --}}
        reveal(target) {
            const bar = document.querySelector('[data-wire=\'admin-topbar\']');
            const top = (bar ? bar.getBoundingClientRect().bottom : 0) + 12;
            const panel = this.$refs.panel;
            const bottom = window.innerHeight - (this.dock && panel ? panel.offsetHeight + 24 : 12);
            const box = target.getBoundingClientRect();
            if (box.top >= top && box.bottom <= bottom) { return; }
            const room = bottom - top;
            const offset = box.height >= room ? top : top + (room - box.height) / 2;
            window.scrollBy({ top: box.top - offset, behavior: 'smooth' });
        },

        place() {
            this.release();
            const index = this.plan[this.cursor];
            if (this.steps[index] && ! this.steps[index].here) { this.go(index); return; }
            const target = this.at(this.cursor);
            {{-- Gone since the plan was built: walk on rather than showing a
                 panel pinned to nothing. --}}
            if (! target) { this.move(1); return; }
            this.dock = this.docked();
            this.report(index);

            {{-- Raised above the backdrop rather than the backdrop being cut
                 out: one z-index instead of an SVG mask that would have to
                 track the element's box anyway. --}}
            this.raised = { el: target, position: target.style.position, zIndex: target.style.zIndex };
            if (getComputedStyle(target).position === 'static') { target.style.position = 'relative'; }
            target.style.zIndex = '61';

            {{-- Docked, the panel is placed by its classes and floats beside
                 nothing; the ring is what points. Measured after the panel has
                 laid out, because how much room is left above it is the
                 question `reveal()` answers. --}}
            if (this.dock) {
                this.unpin();
            } else {
                this.detach = this.$float(target, this.$refs.panel, { placement: this.step.placement || 'bottom', offset: 12 });
            }
            this.$nextTick(() => { this.reveal(target); this.track(); });
        },

        {{-- The ring is our own element over the target's box, so it carries a
             hook name and an application can restyle it. The target's own
             markup is never written to beyond the z-index above, which is
             restored. --}}
        track() {
            const target = this.at(this.cursor);
            if (! target) { return; }
            const box = target.getBoundingClientRect();
            this.rect = { top: box.top, left: box.left, width: box.width, height: box.height };
        },

        {{-- Floating UI writes its position onto the panel's own style and
             leaves it there when detached, and an inline `position: absolute`
             outranks the class that docks the panel: a tour dragged narrow kept
             floating at a left offset from the wide layout, squeezed into a
             sliver. Docking clears what floating wrote. --}}
        unpin() {
            const panel = this.$refs.panel;
            if (! panel) { return; }
            ['position', 'top', 'left', 'right', 'bottom', 'transform', 'zIndex', 'maxHeight', 'minWidth', 'overflowY']
                .forEach((property) => { panel.style[property] = ''; });
        },

        {{-- How far this person got, told to the server once per step shown, so
             a tour they leave halfway reopens there on the next visit. An event
             rather than a call for the reason `done()` gives: the component
             that makes the round trip sits outside this `wire:ignore`. --}}
        report(index) {
            if (this.reported === index) { return; }
            this.reported = index;
            window.dispatchEvent(new CustomEvent('wire-tour:reached', {
                detail: { id: this.tourId, step: index },
            }));
        },

        release() {
            if (this.detach) { this.detach(); this.detach = null; }
            if (this.raised) {
                this.raised.el.style.position = this.raised.position;
                this.raised.el.style.zIndex = this.raised.zIndex;
                this.raised = null;
            }
        },

        move(direction) {
            const next = this.seek(this.cursor + direction, direction);
            if (next === null) { this.done(false); return; }
            this.cursor = next;
            this.$nextTick(() => this.place());
        },

        next() { this.isLast ? this.done(false) : this.move(1); },
        back() { this.move(-1); },

        {{-- Finishing and skipping are the same call. Somebody who skipped has
             decided about this tour as firmly as somebody who finished it, and a
             tour that comes back after a skip is the worst version of this. The
             event is what step 3 listens for to record it. --}}
        done(skipped) {
            this.release();
            this.active = false;
            try { window.sessionStorage.removeItem(this.planKey()); } catch (e) {}
            window.dispatchEvent(new CustomEvent('wire-tour:done', {
                detail: { id: this.tourId, skipped: skipped },
            }));
        },
    }"
    x-init="$nextTick(() => settle().then(() => start()))"
    x-on:keydown.escape.window="active ? done(true) : (greeting && later())"
    x-on:scroll.window.passive="active && track()"
    x-on:resize.window="active && (docked() !== dock ? settle().then(() => place()) : track())"
    wire:ignore
>
    <div
        @wireEl('tour-backdrop')
        x-show="active || greeting"
        x-cloak
        x-transition.opacity
        class="fixed inset-0 z-[60] bg-gray-900/50 dark:bg-gray-950/70"
        aria-hidden="true"
    ></div>

    {{-- The block a tour opens with, when it has one. Centred rather than
         floating beside anything: it is about the tour rather than about an
         element, and there is nothing to point at until somebody has said yes.
         It sits above the backdrop and, unlike the panel, never docks — at any
         width a centred card is the right shape for a question. --}}
@if ($payload['welcome'] !== null)
@if ($payload['welcome']['view'] !== null)
    {{-- An application's own markup instead of the framework's, included inside
         this `x-data` so it has `greeting`, `begin()`, `later()` and the
         `welcome` object in scope. It owns its own visibility: nothing here
         shows it, so it carries its own `x-show="greeting"`. --}}
    @include($payload['welcome']['view'], ['welcome' => $tour->getWelcome(), 'tour' => $tour])
@else
    <div
        @wireEl('tour-welcome')
        x-show="greeting"
        x-cloak
        x-transition.opacity
        @include('wire-core::modals.partials.focus-trap', ['openExpression' => 'greeting'])
        class="fixed inset-0 z-[63] flex items-center justify-center p-4"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('wire-core::messages.tour_region') }}"
    >
        <div class="w-full max-w-sm rounded-xl border border-gray-200 bg-white p-6 text-center shadow-xl dark:border-gray-700 dark:bg-gray-800">
            <p
                @wireEl('tour-welcome-heading')
                x-show="welcome.heading"
                x-text="welcome.heading"
                class="text-base font-semibold text-gray-900 dark:text-gray-100"
            ></p>

            <p
                @wireEl('tour-welcome-text')
                x-show="welcome.text"
                x-text="welcome.text"
                class="mt-2 text-sm text-gray-600 dark:text-gray-300"
            ></p>

            <div class="mt-6 flex items-center justify-center gap-2">
                <button
                    @wireEl('tour-welcome-later')
                    type="button"
                    x-show="welcome.later"
                    x-text="welcome.later"
                    x-on:click="later()"
                    class="rounded-md px-3 py-1.5 text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                ></button>

                <button
                    @wireEl('tour-welcome-start')
                    type="button"
                    x-text="welcome.start"
                    x-on:click="begin()"
                    class="rounded-md bg-primary-600 px-4 py-1.5 text-xs font-medium text-white hover:bg-primary-700"
                ></button>
            </div>
        </div>
    </div>
@endif
@endif

    <div
        @wireEl('tour-highlight')
        x-show="active"
        x-cloak
        x-bind:style="`top:${rect.top - 4}px;left:${rect.left - 4}px;width:${rect.width + 8}px;height:${rect.height + 8}px`"
        class="pointer-events-none fixed z-[62] rounded-lg ring-2 ring-primary-500 ring-offset-2 ring-offset-transparent transition-all duration-150"
        aria-hidden="true"
    ></div>

    <div
        @wireEl('tour-panel')
        x-ref="panel"
        x-show="active"
        x-cloak
        @include('wire-core::modals.partials.focus-trap', ['openExpression' => 'active'])
        x-bind:class="dock ? 'fixed inset-x-3 bottom-3 max-h-[45vh] overflow-y-auto' : 'absolute left-0 top-0 w-72 max-w-[calc(100vw-2rem)]'"
        class="z-[63] rounded-xl border border-gray-200 bg-white p-4 shadow-xl dark:border-gray-700 dark:bg-gray-800"
        role="dialog"
        aria-label="{{ __('wire-core::messages.tour_region') }}"
    >
        <p
            @wireEl('tour-heading')
            x-show="step.heading"
            x-text="step.heading"
            class="mb-1 text-sm font-semibold text-gray-900 dark:text-gray-100"
        ></p>

        <p
            @wireEl('tour-text')
            x-show="step.text"
            x-text="step.text"
            class="text-sm text-gray-600 dark:text-gray-300"
        ></p>

        <div class="mt-4 flex items-center justify-between gap-3">
            <span
                @wireEl('tour-progress')
                x-text="progress"
                class="text-xs tabular-nums text-gray-500 dark:text-gray-400"
            ></span>

            <div class="flex items-center gap-2">
                <button
                    @wireEl('tour-skip')
                    type="button"
                    x-on:click="done(true)"
                    class="rounded-md px-2 py-1 text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                >{{ __('wire-core::messages.tour_skip') }}</button>

                <button
                    @wireEl('tour-back')
                    type="button"
                    x-show="cursor > 0"
                    x-on:click="back()"
                    class="rounded-md border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                >{{ __('wire-core::messages.tour_back') }}</button>

                <button
                    @wireEl('tour-next')
                    type="button"
                    x-on:click="next()"
                    x-text="isLast ? @js(__('wire-core::messages.tour_finish')) : @js(__('wire-core::messages.tour_next'))"
                    class="rounded-md bg-primary-600 px-3 py-1 text-xs font-medium text-white hover:bg-primary-700"
                ></button>
            </div>
        </div>
    </div>
</div>
@endif
