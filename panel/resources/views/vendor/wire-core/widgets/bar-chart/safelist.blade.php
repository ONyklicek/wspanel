{{--
    Tailwind safelist for BarChartWidget — DO NOT DELETE, DO NOT @include.

    The bar fill color and accent text are chosen at runtime by the canonical
    HasColor resolvers (getGradientFillClasses() / getFillTextClasses()) in
    packages/core/src/Foundation/Concerns/HasColor.php. Those literal class
    strings live only in PHP source.

    docs/start/getting-started.md tells a consuming app to scan BOTH the package
    *src* (where these HasColor literals live) and its *views*. This file is the
    safety net for a consumer that scans only *views* (not *src*): without it,
    the gradient/text utilities a bar can use would never be emitted into that
    host's CSS, so bars would render with width/height but no visible color.

    This element is never rendered (it is not @include-d anywhere); it exists only
    so Tailwind's source scanner sees every utility the widget can produce. It is
    kept in sync with the HasColor resolvers by
    packages/core/tests/Unit/Widgets/BarChartSafelistTest.php — update both
    together when the chart palette changes.
--}}
<div hidden aria-hidden="true">
    {{-- Fill direction + value-driven sizing (also used literally in the partials). --}}
    <div class="bg-gradient-to-r bg-gradient-to-t w-[var(--value)] h-[var(--value)]"></div>

    {{-- Gradient fill stops: every variant getGradientFillClasses() can return. --}}
    <div class="from-primary-500 to-primary-600"></div>
    <div class="from-blue-500 to-blue-600"></div>
    <div class="from-green-500 to-green-600"></div>
    <div class="from-emerald-500 to-emerald-600"></div>
    <div class="from-red-500 to-red-600"></div>
    <div class="from-amber-500 to-amber-600"></div>
    <div class="from-yellow-500 to-yellow-600"></div>
    <div class="from-cyan-500 to-cyan-600"></div>
    <div class="from-sky-500 to-sky-600"></div>
    <div class="from-purple-500 to-purple-600"></div>
    <div class="from-violet-500 to-violet-600"></div>
    <div class="from-indigo-500 to-indigo-600"></div>
    <div class="from-orange-500 to-orange-600"></div>
    <div class="from-lime-500 to-lime-600"></div>
    <div class="from-teal-500 to-teal-600"></div>
    <div class="from-fuchsia-500 to-fuchsia-600"></div>
    <div class="from-pink-500 to-pink-600"></div>
    <div class="from-rose-500 to-rose-600"></div>
    <div class="from-slate-500 to-slate-600"></div>
    <div class="from-zinc-500 to-zinc-600"></div>
    <div class="from-neutral-500 to-neutral-600"></div>
    <div class="from-stone-500 to-stone-600"></div>
    <div class="from-gray-700 to-gray-900"></div>
    <div class="from-gray-100 to-gray-300"></div>
    <div class="from-slate-400 to-slate-500"></div>

    {{-- Accent text: every variant getFillTextClasses() can return. --}}
    <div class="text-primary-600 dark:text-primary-400"></div>
    <div class="text-blue-600 dark:text-blue-400"></div>
    <div class="text-green-700 dark:text-green-400"></div>
    <div class="text-emerald-700 dark:text-emerald-400"></div>
    <div class="text-red-600 dark:text-red-400"></div>
    <div class="text-amber-700 dark:text-amber-400"></div>
    <div class="text-yellow-700 dark:text-yellow-400"></div>
    <div class="text-cyan-700 dark:text-cyan-400"></div>
    <div class="text-sky-700 dark:text-sky-400"></div>
    <div class="text-purple-600 dark:text-purple-400"></div>
    <div class="text-violet-600 dark:text-violet-400"></div>
    <div class="text-indigo-600 dark:text-indigo-400"></div>
    <div class="text-orange-700 dark:text-orange-400"></div>
    <div class="text-lime-700 dark:text-lime-400"></div>
    <div class="text-teal-700 dark:text-teal-400"></div>
    <div class="text-fuchsia-600 dark:text-fuchsia-400"></div>
    <div class="text-pink-600 dark:text-pink-400"></div>
    <div class="text-rose-600 dark:text-rose-400"></div>
    <div class="text-zinc-600 dark:text-zinc-400"></div>
    <div class="text-neutral-600 dark:text-neutral-400"></div>
    <div class="text-stone-600 dark:text-stone-400"></div>
    <div class="text-gray-900 dark:text-white"></div>
    <div class="text-white dark:text-gray-900"></div>
    <div class="text-slate-600 dark:text-slate-400"></div>
</div>
