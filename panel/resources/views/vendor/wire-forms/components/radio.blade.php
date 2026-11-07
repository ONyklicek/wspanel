@php
    use NyonCode\WireCore\Foundation\Concerns\HasColor;
    use NyonCode\WireForms\Components\Radio;

    assert($field instanceof Radio);

    $wireModifier = $field->getWireModelModifier();
    $wireAttr = 'wire:model' . ($wireModifier ? ".{$wireModifier}" : '');
    $wireName = $field->getWireModelAttribute();
    $options = $field->getOptions();
    $descriptions = $field->getDescriptions();
    $icons = $field->getIcons();
    $disabled = $field->isDisabled();
    $sizeClasses = $field->getSizeClasses();
    $iconSizeClass = $field->getIconSizeClass();
    $optionColors = $field->getColors();
    $groupColor = $field->getColor();
@endphp

@include('wire-forms::partials.field-wrapper-start')

@if($field->isSegmented())
    {{-- ─── Segmented (pill over a shared track) ────────────────── --}}
    {{-- Full-width equal segments on mobile (intrinsic-width pills would wrap
         into a ragged track); intrinsic inline-flex from sm up. --}}
    <div @class([
            'flex w-full flex-wrap gap-1 rounded-lg border border-gray-200 bg-gray-50 p-1',
            'sm:inline-flex sm:w-auto',
            'dark:border-gray-700 dark:bg-gray-800',
            'opacity-60' => $disabled,
        ]) role="radiogroup">
        @foreach($options as $value => $label)
            @php $cc = HasColor::getChoiceColorClasses($optionColors[$value] ?? $groupColor); @endphp
            <label @class([
                    'group relative flex flex-1 items-center justify-center rounded-md font-medium transition-colors duration-150 sm:flex-none',
                    $sizeClasses,
                    'cursor-pointer' => !$disabled,
                    'cursor-not-allowed' => $disabled,
                ])>
                <input
                        type="radio"
                        data-testid="form-radio-{{ $field->getStatePath() }}-{{ $value }}"
                        id="{{ $field->getId() }}-{{ $value }}"
                        {{ $wireAttr }}="{{ $wireName }}"
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
@elseif($field->isButtons())
    {{-- ─── Buttons (separate buttons, selected filled primary) ─── --}}
    <div @class([
            'flex gap-2',
            'flex-col items-start' => !$field->isInline(),
            'flex-row flex-wrap' => $field->isInline(),
            'opacity-60' => $disabled,
        ]) role="radiogroup">
        @foreach($options as $value => $label)
            @php $cc = HasColor::getChoiceColorClasses($optionColors[$value] ?? $groupColor); @endphp
            <label @class([
                    'relative',
                    'cursor-pointer' => !$disabled,
                    'cursor-not-allowed' => $disabled,
                ])>
                <input
                        type="radio"
                        data-testid="form-radio-{{ $field->getStatePath() }}-{{ $value }}"
                        id="{{ $field->getId() }}-{{ $value }}"
                        {{ $wireAttr }}="{{ $wireName }}"
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
@elseif($field->isCards())
    {{-- ─── Cards ───────────────────────────────────────────────── --}}
    {{-- Inline cards sit in one auto-sized row from sm up, but stack on a phone
         so a row of cards never overflows the viewport horizontally. --}}
    <div @class([
            'grid gap-3',
            'grid-cols-1 sm:grid-cols-none sm:grid-flow-col sm:auto-cols-fr' => $field->isInline(),
        ])>
        @foreach($options as $value => $label)
            @php $cc = HasColor::getChoiceColorClasses($optionColors[$value] ?? $groupColor); @endphp
            <label @class([
                    'relative block',
                    'cursor-pointer' => !$disabled,
                    'cursor-not-allowed opacity-60' => $disabled,
                ])>
                <input
                        type="radio"
                        data-testid="form-radio-{{ $field->getStatePath() }}-{{ $value }}"
                        id="{{ $field->getId() }}-{{ $value }}"
                        {{ $wireAttr }}="{{ $wireName }}"
                        value="{{ $value }}"
                        @disabled($disabled)
                        class="peer sr-only"
                />
                <div @class([
                        'flex items-start gap-3 rounded-lg border border-gray-200 bg-white p-4 transition-all duration-150 peer-checked:ring-2',
                        'hover:border-gray-300' => !$disabled,
                        $cc['card'],
                        'peer-checked:[&_.wf-card-dot]:opacity-100' => $field->hasIndicator(),
                        $cc['indicator'] => $field->hasIndicator(),
                        'dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600',
                    ])>
                    @if(isset($icons[$value]))
                        {!! icon($icons[$value], 'w-4 h-4', 'wf-card-icon mt-0.5 h-6 w-6 flex-shrink-0 text-gray-400 transition-colors duration-150') !!}
                    @endif
                    <div class="min-w-0 flex-1">
                        <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ $label }}</span>
                        @if(isset($descriptions[$value]))
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $descriptions[$value] }}</p>
                        @endif
                    </div>
                    @if($field->hasIndicator())
                        <span aria-hidden="true" class="wf-card-indicator mt-0.5 flex h-4 w-4 flex-shrink-0 items-center justify-center rounded-full border border-gray-300 transition-colors duration-150 dark:border-gray-600">
                            <span class="wf-card-dot h-1.5 w-1.5 rounded-full bg-white opacity-0 transition-opacity duration-150"></span>
                        </span>
                    @endif
                </div>
            </label>
        @endforeach
    </div>
@else
    {{-- ─── Default radio list ──────────────────────────────────── --}}
    <div @class([
            'space-y-2' => !$field->isInline(),
            'flex flex-wrap gap-4' => $field->isInline(),
        ])>
        @foreach($options as $value => $label)
            @php $cc = HasColor::getChoiceColorClasses($optionColors[$value] ?? $groupColor); @endphp
            <div class="flex items-start gap-2">
                <input
                        type="radio"
                        data-testid="form-radio-{{ $field->getStatePath() }}-{{ $value }}"
                        id="{{ $field->getId() }}-{{ $value }}"
                        {{ $wireAttr }}="{{ $wireName }}"
                        value="{{ $value }}"
                        @disabled($disabled)
                        class="mt-0.5 border-gray-300 {{ $cc['input'] }} transition-colors duration-150 dark:bg-gray-800 dark:border-gray-600"
                />
                <div>
                    <label for="{{ $field->getId() }}-{{ $value }}" class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                        @if(isset($icons[$value]))
                            {!! icon($icons[$value], 'w-4 h-4', 'h-4 w-4') !!}
                        @endif
                        {{ $label }}
                    </label>
                    @if(isset($descriptions[$value]))
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $descriptions[$value] }}</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif

@include('wire-forms::partials.field-wrapper-end')
