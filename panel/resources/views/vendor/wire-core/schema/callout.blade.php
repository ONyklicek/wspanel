@php
    use NyonCode\WireCore\Foundation\Schema\Callout;

    assert($layout instanceof Callout);

    // Body: rendered child components (schema) if any, else the escaped content
    // string. Children are Htmlable, so their markup is already safe.
    $body = '';
    foreach ($layout->getSchema() as $child) {
        if ($child->isVisible()) {
            $body .= (string) $child;
        }
    }
    if ($body === '' && $layout->getContent() !== null) {
        $body = e($layout->getContent());
    }
@endphp

@include('wire-core::partials.callout', [
    'colorClasses' => $layout->getColorClasses(),
    'icon' => $layout->getIcon(),
    'heading' => $layout->getHeading(),
    'body' => $body,
    'dismissible' => $layout->isDismissible(),
])
