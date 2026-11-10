{{-- Background tasks in the top bar: polled while any runs, quiet otherwise
     (a page that starts one says so with agent-task-started). --}}
<div data-testid="task-indicator" @if ($running > 0) wire:poll.3s="settle" @endif>
    @if ($recent->isNotEmpty())
        <x-wire::dropdown position="bottom-end" width="w-80" sheet-on-mobile>
            <x-slot:trigger>
                <button type="button" data-testid="task-indicator-trigger"
                    @class([
                        'inline-flex items-center gap-2 rounded-full border px-3 py-2.5 text-sm transition sm:py-1.5',
                        'border-primary-200 bg-primary-50 text-primary-700 hover:bg-primary-100 dark:border-primary-500/30 dark:bg-primary-500/10 dark:text-primary-300' => $running > 0,
                        'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800' => $running === 0,
                    ])>
                    @if ($running > 0)
                        {!! icon('outline:arrow-path', 'size-4 shrink-0 animate-spin') !!}
                        <span>{{ trans_choice('{1} :count úloha běží|[2,4] :count úlohy běží|[5,*] :count úloh běží', $running, ['count' => $running]) }}</span>
                    @else
                        {!! icon('outline:check-circle', 'size-4 shrink-0') !!}
                        <span class="hidden sm:inline">{{ __('Úlohy') }}</span>
                    @endif
                </button>
            </x-slot:trigger>

            <div class="px-4 py-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Úlohy na pozadí') }}</div>
            <ul class="max-h-96 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                @foreach ($recent as $task)
                    <li wire:key="task-{{ $task->id }}" class="flex items-start gap-3 px-4 py-2.5 text-sm">
                        @if ($task->isRunning())
                            {!! icon('outline:arrow-path', 'mt-0.5 size-4 shrink-0 animate-spin text-primary-600 dark:text-primary-400') !!}
                        @elseif ($task->status === 'done')
                            {!! icon('outline:check-circle', 'mt-0.5 size-4 shrink-0 text-success-600 dark:text-success-400') !!}
                        @else
                            {!! icon('outline:x-circle', 'mt-0.5 size-4 shrink-0 text-danger-600 dark:text-danger-400') !!}
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-gray-900 dark:text-gray-100">{{ $task->title }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($task->isRunning())
                                    {{ __('běží od :time', ['time' => $task->started_at->setTimezone(config('app.timezone'))->format('H:i')]) }}
                                @elseif ($task->status === 'done')
                                    {{ __('hotovo :when', ['when' => $task->finished_at?->diffForHumans()]) }}
                                @else
                                    <span class="text-danger-600 dark:text-danger-400">{{ \Illuminate\Support\Str::limit((string) $task->error, 140) }}</span>
                                @endif
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-wire::dropdown>
    @endif
</div>
