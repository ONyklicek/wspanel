@if ($backgroundJobs !== [])
    <div wire:poll.3s="pollBackgroundJobs" class="space-y-2">
        @foreach ($backgroundJobs as $key => $job)
            <div wire:key="bg-{{ $key }}" class="flex items-center gap-3 rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 text-sm dark:border-primary-500/30 dark:bg-primary-500/10">
                {!! icon('outline:arrow-path', 'size-4 shrink-0 animate-spin text-primary-600 dark:text-primary-400') !!}
                <span class="font-medium">{{ $job['title'] }}</span>
                <span class="text-gray-500 dark:text-gray-400">{{ __('běží na pozadí') }} <x-panel.elapsed :since="\Illuminate\Support\Carbon::parse($job['started'])" /></span>
            </div>
        @endforeach
    </div>
@endif
