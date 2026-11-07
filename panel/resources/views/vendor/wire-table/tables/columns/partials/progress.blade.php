{{-- Progress bar used by PollColumn::forProgress(). --}}
@php
    /** @var float|int $percentage */
@endphp
<div class="flex items-center gap-2">
    <div class="w-24 bg-gray-200 dark:bg-gray-700 rounded-full h-2">
        <div class="bg-primary-600 h-2 rounded-full transition-all duration-300" style="width: {{ $percentage }}%"></div>
    </div>
    <span class="text-sm text-gray-600 dark:text-gray-400">{{ $percentage }}%</span>
</div>
