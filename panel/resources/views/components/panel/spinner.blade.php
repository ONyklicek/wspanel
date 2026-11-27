{{--
    A button's icon that turns while something runs, so the button does not
    look idle: while the click's request runs (target: the Livewire method,
    as for wire:target) and, with busy, for as long as the background job it
    started runs. Without an icon only the turning arrow, and only then.
--}}
@props(['target', 'busy' => false, 'icon' => null])
@php($spin = icon('outline:arrow-path', 'size-4 shrink-0 animate-spin'))
@if ($busy)
    {!! $spin !!}
@else
    @if ($icon)
        <span wire:loading.remove wire:target="{{ $target }}" class="inline-flex">{!! icon($icon, 'size-4 shrink-0') !!}</span>
    @endif
    <span wire:loading.inline-flex wire:target="{{ $target }}">{!! $spin !!}</span>
@endif
