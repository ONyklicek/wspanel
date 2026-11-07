{{-- The option grid of a CheckboxList. Shared by the flat and grouped layouts so
     the two can never drift apart.

     Included ONCE per grid, never once per option: an `@include` inside a
     `@foreach` is a view render per option, measured at 10.25 → 4.49 ms for a
     100-option list. The loop therefore lives in here rather than at the call
     site, which keeps the single Blade owner the two layouts share.
     `FormRenderCountTest` pins the per-option slope at zero.

     Variables: $field, $wireAttr, $options, $gridClasses --}}
<div class="{{ $gridClasses }}">
    @foreach($options as $value => $label)
        <div
            class="flex items-center gap-2"
            @if($field->isSearchable()) x-show="matches(@js($label))" @endif
        >
            <input
                type="checkbox"
                id="{{ $field->getId() }}-{{ $value }}"
                data-testid="form-checklist-{{ $field->getStatePath() }}-{{ $value }}"
                {{ $wireAttr }}="{{ $field->getWireModelAttribute() }}"
                value="{{ $value }}"
                @if($field->isDisabled()) disabled @endif
                class="rounded-sm border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 transition-colors duration-150 dark:bg-gray-800 dark:border-gray-600"
            />
            <label for="{{ $field->getId() }}-{{ $value }}" class="text-sm text-gray-700 dark:text-gray-300">
                {{ $label }}
            </label>
        </div>
    @endforeach
</div>
