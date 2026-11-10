{{-- What runs in the background for this record (WatchesAgentTasks), live;
     drawn under every record page's heading (AppServiceProvider). --}}
@php($subjectTasks = $component->subjectTasks())
@if ($subjectTasks->isNotEmpty())
    <div wire:poll.3s="settleSubjectTasks" class="space-y-2" data-testid="subject-tasks">
        @foreach ($subjectTasks as $task)
            <div wire:key="subject-task-{{ $task->id }}" class="flex items-center gap-3 rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 text-sm dark:border-primary-500/30 dark:bg-primary-500/10">
                {!! icon('outline:arrow-path', 'size-4 shrink-0 animate-spin text-primary-600 dark:text-primary-400') !!}
                <span class="font-medium">{{ $task->title }}</span>
                <span class="text-gray-500 dark:text-gray-400">{{ __('běží na pozadí od :time', ['time' => $task->started_at->setTimezone(config('app.timezone'))->format('H:i')]) }}</span>
            </div>
        @endforeach
    </div>
@endif
