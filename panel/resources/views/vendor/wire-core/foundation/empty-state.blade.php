@include('wire-core::partials.empty-state', [
    'icon' => $icon,
    'heading' => $heading,
    'description' => $description,
    'actions' => trim($slot) !== '' ? [trim($slot)] : [],
])
