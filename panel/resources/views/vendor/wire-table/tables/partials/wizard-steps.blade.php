{{-- Multi-step wizard progress indicator.
     Expects: $steps (array of step config), $currentStep (int). --}}
<nav aria-label="{{ __('Progress') }}" class="mb-6">
    <ol role="list" class="flex w-full items-center gap-2">
        @foreach($steps as $index => $step)
            @php
                $isDone = $index < $currentStep;
                $isCurrent = $index === $currentStep;
            @endphp
            <li @class(['flex items-center gap-2', 'flex-1' => ! $loop->last]) aria-current="{{ $isCurrent ? 'step' : 'false' }}">
                <span @class([
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold transition-colors',
                    'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $isCurrent,
                    'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-200' => $isDone,
                    'bg-gray-100 text-gray-600 dark:text-gray-400 dark:bg-gray-800' => ! $isCurrent && ! $isDone,
                ])>
                    @if($isDone)
                        {!! icon('check', 'w-4 h-4', 'h-4 w-4') !!}
                    @else
                        {{ $index + 1 }}
                    @endif
                </span>

                <span class="hidden min-w-0 flex-col sm:flex">
                    <span @class([
                        'truncate text-sm font-medium',
                        'text-gray-900 dark:text-gray-100' => $isCurrent || $isDone,
                        'text-gray-500 dark:text-gray-400' => ! $isCurrent && ! $isDone,
                    ])>{{ $step['label'] ?? '' }}</span>
                    @if(! empty($step['description']))
                        <span class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $step['description'] }}</span>
                    @endif
                </span>

                @unless($loop->last)
                    <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
                @endunless
            </li>
        @endforeach
    </ol>
</nav>
