@php
    use NyonCode\WireCore\Foundation\Schema\Tabs;

    assert($layout instanceof Tabs);

    $tabs = $layout->getTabs();
@endphp

<div x-data="{ activeTab: {{ $layout->getActiveTab() }} }">
    {{-- Tab bar --}}
    {{-- Scrollable on narrow screens — wrapping intrinsic-width tabs reads as
         two ragged rows; a single scrollable row is the standard mobile pattern. --}}
    <div role="tablist" class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-700">
        @foreach($tabs as $index => $tab)
            <button
                type="button"
                role="tab"
                :aria-selected="activeTab === {{ $index }}"
                @click="activeTab = {{ $index }}"
                data-testid="tab-{{ $index }}"
                @class([
                    'flex shrink-0 items-center gap-2 whitespace-nowrap px-4 py-2 -mb-px text-sm font-medium border-b-2 transition-colors duration-150 focus:outline-none',
                ])
                :class="activeTab === {{ $index }}
                    ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300'"
            >
                @if($tab->getIcon())
                    {!! icon($tab->getIcon(), 'w-4 h-4', 'w-4 h-4 shrink-0') !!}
                @endif
                <span>{{ $tab->getLabel() }}</span>
            </button>
        @endforeach
    </div>

    {{-- Panels: all kept in the DOM so nested fields validate together. --}}
    @foreach($tabs as $index => $tab)
        <div role="tabpanel" x-show="activeTab === {{ $index }}" x-cloak class="pt-4">
            {{ $tab }}
        </div>
    @endforeach
</div>
