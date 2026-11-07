{{-- The filter control every widget that has options draws.

     A partial here and not on the heading, deliberately. The four widget
     surfaces wrap their heading differently on purpose and collapsing them is
     what CLAUDE.md forbids — but the control itself is one thing with one
     behaviour on every surface: pick a key, tell the host, let the server
     resolve the closure. Splitting *that* into four copies is the duplication
     the same rule forbids from the other direction.

     `filterExpression` is null when the widget has no key to be addressed by,
     and then the select is inert rather than absent: the options are still worth
     showing, and a control that silently called nothing would be worse than one
     that visibly does nothing.

     Variables: $widget, $filterOptions, $activeFilter, $filterExpression --}}
<select
    {{-- Escaped, like `wire:click` on the shipped action button two directories
         over — the quotes around the widget key become `&#039;` in the source and
         the HTML parser hands Livewire back the string it wrote. The poll
         directive next door is raw for a different reason: there PHP produces the
         whole attribute, not a value inside one. --}}
    @if($filterExpression) wire:change="{{ $filterExpression }}" @endif
    {{-- Renamed from `chart-filter` when this stopped being a chart's control.
         Nothing in the repo held the old name, and a hook that says "chart" on a
         list widget is a hook that lies. --}}
    data-testid="widget-filter" @wireEl('widget-filter')
    aria-label="{{ $widget->getHeading() ?? __('wire-core::messages.widget_filter') }}"
    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-base sm:text-sm text-gray-700 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300">
    @foreach($filterOptions as $key => $label)
        <option value="{{ $key }}" @selected($key === $activeFilter)>{{ $label }}</option>
    @endforeach
</select>
