{{-- SplitColumn cell.

     Every child sits in a box of its own. A child's markup is often bare text,
     and bare text dropped straight into a flex container is one anonymous flex
     item: a `vertical()` split of two amounts printed "500 Kč1 000 Kč" on one
     line, and the text beside an image ran together the same way. --}}
@php
    /** @var string $layout horizontal|vertical */
    /** @var string $alignClass */
    /** @var string $justifyClass */
    /** @var string $gapClass */
    /** @var string $imageHtml pre-rendered image column markup (may be empty) */
    /** @var array<int, array{html: string, class: string}> $children pre-rendered child markup + its own alignment class */
@endphp

@if($layout === 'horizontal' && $imageHtml)
    <div class="flex {{ $alignClass }} {{ $gapClass }}">
        {!! $imageHtml !!}
        <div class="min-w-0 flex-1">@foreach($children as $child)<div class="{{ $child['class'] }}">{!! $child['html'] !!}</div>@endforeach</div>
    </div>
@elseif($layout === 'horizontal')
    <div class="flex {{ $alignClass }} {{ $justifyClass }} {{ $gapClass }}">@foreach($children as $child)<div class="min-w-0 {{ $child['class'] }}">{!! $child['html'] !!}</div>@endforeach</div>
@else
    {{-- $alignClass is empty unless the author set one: a column has always
         stretched its children, and the row's default would re-lay-out every
         existing vertical split. --}}
    <div class="flex flex-col {{ $alignClass }} {{ $gapClass }}">@if($imageHtml)<div>{!! $imageHtml !!}</div>@endif @foreach($children as $child)<div class="{{ $child['class'] }}">{!! $child['html'] !!}</div>@endforeach</div>
@endif
