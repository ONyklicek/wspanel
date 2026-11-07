{{-- CheckboxList rendered as the shared choice chrome (segmented / buttons).
     Same markup as the matching Radio variants; only the input type differs, so
     several options can be selected at once. --}}
@php
    use NyonCode\WireForms\Components\CheckboxList;

    assert($field instanceof CheckboxList);

    $disabled = $field->isDisabled();
    $sizeClasses = $field->getSizeClasses();
    $iconSizeClass = $field->getIconSizeClass();
    $icons = $field->getIcons();
@endphp

@if($field->isSegmented())
    {{-- Full-width equal segments on mobile; intrinsic inline-flex from sm up. --}}
    <div @class([
            'flex w-full flex-wrap gap-1 rounded-lg border border-gray-200 bg-gray-50 p-1',
            'sm:inline-flex sm:w-auto',
            'dark:border-gray-700 dark:bg-gray-800',
            'opacity-60' => $disabled,
        ]) role="group">
        @foreach($options as $value => $label)
            @php $cc = $field->getColorClassesFor($value); @endphp
            <label @class([
                    'group relative flex flex-1 items-center justify-center rounded-md font-medium transition-colors duration-150 sm:flex-none',
                    $sizeClasses,
                    'cursor-pointer' => !$disabled,
                    'cursor-not-allowed' => $disabled,
                ])>
                <input
                        type="checkbox"
                        data-testid="form-checklist-{{ $field->getStatePath() }}-{{ $value }}"
                        id="{{ $field->getId() }}-{{ $value }}"
                        {{ $wireAttr }}="{{ $field->getWireModelAttribute() }}"
                        value="{{ $value }}"
                        @disabled($disabled)
                        class="peer sr-only"
                />
                <span aria-hidden="true" @class([
                        'absolute inset-0 rounded-md bg-transparent transition-colors duration-150',
                        'peer-checked:bg-white peer-checked:shadow-sm peer-checked:ring-1 peer-checked:ring-black/5',
                        'dark:peer-checked:bg-gray-900 dark:peer-checked:ring-white/10',
                    ])></span>
                <span @class([
                        'relative z-10 flex items-center gap-2 text-gray-600 dark:text-gray-300',
                        $cc['text'],
                    ])>
                    @if(isset($icons[$value]))
                        {!! icon($icons[$value], 'w-4 h-4', $iconSizeClass) !!}
                    @endif
                    {{ $label }}
                </span>
            </label>
        @endforeach
    </div>
@else
    <div @class([
            'flex gap-2',
            'flex-col items-start' => !$field->isInline(),
            'flex-row flex-wrap' => $field->isInline(),
            'opacity-60' => $disabled,
        ]) role="group">
        @foreach($options as $value => $label)
            @php $cc = $field->getColorClassesFor($value); @endphp
            <label @class([
                    'relative',
                    'cursor-pointer' => !$disabled,
                    'cursor-not-allowed' => $disabled,
                ])>
                <input
                        type="checkbox"
                        data-testid="form-checklist-{{ $field->getStatePath() }}-{{ $value }}"
                        id="{{ $field->getId() }}-{{ $value }}"
                        {{ $wireAttr }}="{{ $field->getWireModelAttribute() }}"
                        value="{{ $value }}"
                        @disabled($disabled)
                        class="peer sr-only"
                />
                {{-- Grey hover belongs to the unselected face only; a selected one
                     darkens its own accent, which $cc['solid'] carries as a
                     `peer-checked:hover:` pair. Both halves are load-bearing:
                     `dark:` is a zero-specificity :where() variant and
                     `peer-checked:` ties with `hover:`, so a lone light-mode
                     `hover:bg-gray-50` used to win in the dark *and* over the
                     accent — repainting a selected button near-white under its
                     own white label. --}}
                <span @class([
                        'flex items-center rounded-lg border border-gray-300 bg-white font-medium text-gray-700 transition-colors duration-150',
                        $sizeClasses,
                        'hover:border-gray-400 hover:bg-gray-100' => !$disabled,
                        'dark:hover:border-gray-500 dark:hover:bg-gray-700' => !$disabled,
                        $cc['solid'],
                        'dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300',
                    ])>
                    @if(isset($icons[$value]))
                        {!! icon($icons[$value], 'w-4 h-4', $iconSizeClass) !!}
                    @endif
                    {{ $label }}
                </span>
            </label>
        @endforeach
    </div>
@endif
