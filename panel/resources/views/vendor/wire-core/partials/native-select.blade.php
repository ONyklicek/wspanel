{{-- The browser's own <select>, styled to match the combobox trigger
     (wire-core::partials.searchable-select) so the two read as one design.

     The single owner of this markup for every select surface — the forms Select
     and BelongsToSelect, the table SelectFilter and TernaryFilter. Reached through
     wire-core::partials.select-control, which decides whether it renders at all.

     Expected variables:
       $selectId        string                    DOM id
       $wireModel       string                    the binding attribute, modifiers
                                                  included (`wire:model.live`)
       $statePath       string                    Wire property path
       $options         array<array-key, string>  value => label map
       $placeholder     string|null               label of the empty choice, which a
                                                  single select always has (a multiple
                                                  list has no "none" row)
       $multiple        bool
       $disabled        bool
       $required        bool                      `required`; false in responsive
                                                  mode, where the hidden twin would
                                                  block the form (aria-required stays)
       $ariaRequired    bool
       $hasError        bool
       $disabledValues  list<string>              option values that cannot be picked
       $selectedValues  list<string>|null         pre-selected for the first paint;
                                                  wire:model owns it after that
       $ariaLabel       string|null               accessible name when a <label for>
                                                  points at the custom twin instead
       $testId          string|null
       $extraInputAttributes string|null          pre-rendered attribute HTML
       $visibilityClass string|null               wraps the element in a div with this
                                                  class (Mobile mode's show-below). On a
                                                  wrapper, because `hidden` beside the
                                                  element's own `block` is a tie the
                                                  stylesheet order breaks, not the markup
--}}
@if($visibilityClass)
<div class="{{ $visibilityClass }}">
@endif
<select
    id="{{ $selectId }}"
    {{ $wireModel }}="{{ $statePath }}"
    @if($testId) data-testid="{{ $testId }}" @endif
    {!! $extraInputAttributes ?? '' !!}
    @if($ariaLabel) aria-label="{{ $ariaLabel }}" @endif
    @if($multiple) multiple @endif
    @if($disabled) disabled @endif
    @if($required) required @elseif($ariaRequired) aria-required="true" @endif
    @class([
        // 16px below sm: iOS Safari zooms the page into any control under 16px
        // the moment it is tapped, and a phone is where this element is used.
        'block w-full rounded-md border border-gray-300 bg-white shadow-sm text-base sm:text-sm',
        'focus:border-primary-500 focus:ring-1 focus:ring-primary-500',
        'hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150',
        'disabled:opacity-50 disabled:cursor-not-allowed',
        'dark:bg-gray-800 dark:border-gray-600 dark:text-white',
        'border-red-500' => $hasError,
    ])
>
    {{-- A single select always has an empty row. Without one the browser shows
         the first option while the state is still null — the field looks filled
         and submits nothing. On an optional field the row is a real choice — it
         is how a phone clears the value — labelled by the placeholder, or a dash
         rather than a nameless row in the wheel. On a required field it is only
         the blank starting point, so it is disabled and hidden. --}}
    @if(! $multiple && ($required || $ariaRequired))
        <option value="" disabled hidden @if($selectedValues === []) selected @endif>{{ $placeholder }}</option>
    @elseif(! $multiple)
        <option value="" @if($selectedValues === []) selected @endif>{{ $placeholder !== null && $placeholder !== '' ? $placeholder : '—' }}</option>
    @endif
    @foreach($options as $optionValue => $optionLabel)
        <option value="{{ $optionValue }}"@if(in_array((string) $optionValue, $disabledValues, true)) disabled @endif @if($selectedValues !== null && in_array((string) $optionValue, $selectedValues, true)) selected @endif>{{ $optionLabel }}</option>
    @endforeach
</select>
@if($visibilityClass)
</div>
@endif
