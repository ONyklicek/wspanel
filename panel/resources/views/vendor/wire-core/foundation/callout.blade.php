@include('wire-core::partials.callout', [
    'colorClasses' => $colorClasses,
    'icon' => $icon,
    'heading' => $heading,
    'body' => trim($slot),
    'dismissible' => $dismissible,
])
