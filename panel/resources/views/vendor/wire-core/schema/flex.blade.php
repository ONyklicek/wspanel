@php
    use NyonCode\WireCore\Foundation\Schema\Flex;

    assert($layout instanceof Flex);
@endphp

<div @class([
    'flex flex-col',
    $layout->getRowClass(),
    $layout->getGapClass(),
    $layout->getJustifyClass(),
    $layout->getAlignClass(),
    'flex-wrap' => $layout->isWrap(),
])>
    @foreach($layout->getSchema() as $component)
        @if($component->isVisible())
            <div @class(['min-w-0', 'flex-1' => $layout->isGrow()])>{{ $component }}</div>
        @endif
    @endforeach
</div>
