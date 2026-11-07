{{--
    Renders a resolved icon as a single <svg> root.

    Using $attributes->merge() (instead of a pre-built string) means any attribute
    that isn't a component prop — Alpine bindings (:class, x-show), data-*, @click —
    is forwarded natively onto the <svg>, and user classes are merged with the
    icon's own size/class. This lets one <x-wire::icon> carry dynamic behaviour
    instead of forcing a hand-written inline <svg>.
--}}
@php
    $rootAttributes = $styleAttributes;
    if ($classes !== '') {
        $rootAttributes['class'] = $classes;
    }
    $rootAttributes['viewBox'] = $viewBox;
    $rootAttributes += $label !== ''
        ? ['role' => 'img', 'aria-label' => $label]
        : ['aria-hidden' => 'true'];
@endphp
<svg {{ $attributes->except(['name', 'size', 'class', 'label'])->merge($rootAttributes) }}>{!! $body !!}</svg>
