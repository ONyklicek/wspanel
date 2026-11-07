{{-- Keyboard shortcut help: the body of the `?` modal.

     The rows come from Table::shortcutLegend() as data (ShortcutHint value
     objects), so this view only lays them out. Each key renders its non-Mac
     label server-side and swaps to the Mac glyphs through x-text — the
     platform is a client fact, and pre-rendering both would either flash or
     need x-cloak. --}}
@php
    use NyonCode\WireCore\Foundation\ValueObjects\ShortcutHint;

    /** @var array<int, array{heading: string, hints: array<int, ShortcutHint>}> $sections */
@endphp
<div
    x-data="{ mac: /Mac|iPhone|iPad|iPod/i.test(navigator.userAgentData?.platform ?? navigator.platform ?? '') }"
    data-testid="shortcut-help" @wireEl('shortcut-help')
    class="space-y-5"
>
    @foreach($sections as $section)
        <section>
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ $section['heading'] }}
            </h4>

            <dl class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($section['hints'] as $hint)
                    @php
                        $plainLabels = $hint->labels();
                        $macLabels = $hint->labels(mac: true);
                    @endphp
                    <div class="flex items-baseline justify-between gap-6 py-1.5">
                        <dt class="text-sm text-gray-700 dark:text-gray-300">{{ $hint->description }}</dt>
                        <dd class="flex shrink-0 items-center gap-1">
                            @foreach($plainLabels as $i => $label)
                                @if($i > 0)
                                    <span class="text-xs text-gray-400 dark:text-gray-500">/</span>
                                @endif
                                <kbd
                                    class="rounded-md border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 px-1.5 py-0.5 font-sans text-xs font-medium text-gray-700 dark:text-gray-300 shadow-sm"
                                    x-text="mac ? @js($macLabels[$i]) : @js($label)"
                                >{{ $label }}</kbd>
                            @endforeach
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endforeach
</div>
