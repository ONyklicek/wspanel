@php
    /**
     * Canonical empty-state surface: a centered icon, heading, description and
     * optional action buttons. Shared by Foundation\Schema\EmptyState, the table's
     * "no records" state and the standalone <x-wire::empty-state> tag.
     *
     * @var string|null            $icon         Icon name (rendered in a soft circle).
     * @var string                 $iconSize     Icon size utility (default h-8 w-8).
     * @var string|null            $heading      Bold heading line.
     * @var string|null            $description  Muted description line.
     * @var array<int, string>     $actions      Pre-rendered action HTML.
     */
    $icon ??= null;
    $iconSize ??= 'h-8 w-8';
    $heading ??= null;
    $description ??= null;
    $actions ??= [];
@endphp

<div class="flex flex-col items-center gap-3 text-center" @wireEl('empty-state')>
    @if($icon)
        <div class="rounded-full bg-gray-100 dark:bg-gray-700 p-3">
            {!! icon($icon, $iconSize, 'text-gray-400') !!}
        </div>
    @endif

    @if($heading || $description)
        <div>
            @if($heading)
                <h3 class="text-base font-medium text-gray-900 dark:text-white">{{ $heading }}</h3>
            @endif
            @if($description)
                <p @class(['text-sm text-gray-500 dark:text-gray-400', 'mt-1' => $heading])>{{ $description }}</p>
            @endif
        </div>
    @endif

    @if(! empty($actions))
        <div class="mt-2 flex flex-wrap items-center justify-center gap-3">
            @foreach($actions as $action)
                {!! $action !!}
            @endforeach
        </div>
    @endif
</div>
