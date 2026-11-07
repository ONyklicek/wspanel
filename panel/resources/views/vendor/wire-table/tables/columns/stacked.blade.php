{{-- StackedColumn cell --}}
@php
    use Illuminate\Contracts\Support\Htmlable;

    assert($linesHtml instanceof Htmlable);

    /** @var string|null $avatarUrl */
    /** @var string $avatarClasses */
    /** @var array<int, array{class: string, value: string}> $items */
    /** @var bool $customStack */
@endphp

@if($customStack)
    @if($avatarUrl)
        <div class="flex items-center gap-3">
            <img src="{{ $avatarUrl }}" class="{{ $avatarClasses }} object-cover" alt="">
            <div>{!! $linesHtml !!}</div>
        </div>
    @else
        <div>{!! $linesHtml !!}</div>
    @endif
@elseif($avatarUrl && count($items))
    <div class="flex items-center gap-3">
        <img src="{{ $avatarUrl }}" class="{{ $avatarClasses }} object-cover" alt="">
        <div>{!! $linesHtml !!}</div>
    </div>
@elseif($avatarUrl)
    <img src="{{ $avatarUrl }}" class="{{ $avatarClasses }} object-cover" alt="">
@elseif(count($items))
    {!! $linesHtml !!}
@else
    <span class="text-gray-500 dark:text-gray-400">—</span>
@endif
