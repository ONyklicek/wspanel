@php
    use NyonCode\WireCore\Foundation\Schema\EmptyState;

    assert($layout instanceof EmptyState);
@endphp

@include('wire-core::partials.empty-state', [
    'icon' => $layout->getIcon(),
    'heading' => $layout->getHeading(),
    'description' => $layout->getDescription(),
    'actions' => $layout->getActionsHtml(),
])
