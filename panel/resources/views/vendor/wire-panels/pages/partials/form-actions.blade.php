{{-- The package's submit row, with the button saying what it does on a page
     that names it (submitLabel(): "Vytvořit web" rather than "Uložit"). --}}
@php
    use NyonCode\WireCore\Foundation\View\Button;
    use NyonCode\WireCore\Foundation\View\ComponentRenderer;
@endphp
<div class="flex flex-col gap-2 sm:flex-row sm:items-center">
    {!! ComponentRenderer::render(
        new Button(size: 'md', type: 'submit'),
        method_exists($this, 'submitLabel') ? $this->submitLabel() : __('wire-panels::messages.save'),
        ['class' => 'w-full justify-center sm:w-auto'],
    ) !!}
</div>
