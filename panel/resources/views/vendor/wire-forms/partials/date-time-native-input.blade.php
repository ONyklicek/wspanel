{{-- The browser's own date/time control, shared by every picker that offers a
     native one (DateTimePicker, TimePicker). The field decides its shape:

       - a date, a month, or a clock without a step: one native input;
       - a time with a step (every TimePicker): a native <select> of the slots;
       - a datetime with a step: a native date input beside that <select>,
         joined into the one state by `wireNativeDateTime`.

     A step never goes on an <input type="time">: to the browser it is a
     validation rule, not a wheel, and iOS offers every minute regardless — the
     pick would then be refused. A select can only give back a slot.

     Extracted rather than copied: the two pickers differ only in their custom
     panel, and a duplicated native branch is the half that would silently drift.

     Expects: $field, $wireAttr, $wireModifier, $minBound, $maxBound, $livewire,
     and $responsive — true when this is the phone twin of the custom picker
     (->nativeOnMobile()). The twin takes its own id (the field label
     points at it below the breakpoint), names itself, and swaps `required` for
     aria-required: on a desktop it is display:none, and a required hidden control would stop
     the form submitting. The same holds for a picker drawn below the
     breakpoint only (a DateRangePicker's two ends). --}}
@php
    $ariaRequiredOnly = $responsive || $field->isBelowBreakpointOnly();
    $nativeId = $responsive ? $field->mobileControlIdFor($field->getId()) : $field->getId();
    $nativeLabel = $responsive ? $field->getLabel() : null;
    $nativeTestId = 'form-datetime-'.$field->getStatePath().'-native';
    $state = $livewire ? data_get($livewire, $field->getStatePath()) : null;
    $slotted = $field->usesNativeSlots();
    $nativeStep = $slotted ? null : $field->getNativeStep();
    // 16px below sm: iOS Safari zooms the page into any control under 16px the
    // moment it is tapped, and a phone is where this control is used.
    $inputClasses = \Illuminate\Support\Arr::toCssClasses([
        'block w-full rounded-md border-gray-300 shadow-sm',
        'focus:border-primary-500 focus:ring-primary-500',
        'hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150',
        'dark:bg-gray-800 dark:border-gray-600 dark:text-white text-base sm:text-sm',
        'border-red-500 focus:border-red-500 focus:ring-red-500' => $errors->has($field->getStatePath()),
    ]);
    $slotSelect = [
        'multiple' => false,
        'placeholder' => $field->getPlaceholder(),
        'disabled' => $field->isDisabled() || $field->isReadOnly(),
        'required' => $field->isRequired() && ! $ariaRequiredOnly,
        'ariaRequired' => $field->isRequired() && $ariaRequiredOnly,
        'hasError' => $errors->has($field->getStatePath()),
        'disabledValues' => [],
        'selectedValues' => null,
        'visibilityClass' => null,
    ];
@endphp

@if($responsive)<div class="{{ \NyonCode\WireCore\Foundation\Support\MobileSheet::showBelow($field->getMobileBreakpoint()) }}">@endif

@if($slotted && $field->getMode() === 'time')
    @include('wire-core::partials.native-select', $slotSelect + [
        'selectId' => $nativeId,
        'wireModel' => $wireAttr,
        'statePath' => $field->getWireModelAttribute(),
        'options' => $field->getSlotOptions($state),
        'ariaLabel' => $nativeLabel,
        'testId' => $nativeTestId,
        'extraInputAttributes' => $field->getExtraInputAttributesHtml(),
    ])
@elseif($slotted)
    <div
        x-data="wireNativeDateTime({
            state: $wire.entangle('{{ $field->getWireModelAttribute() }}'){{ $wireModifier ? '.' . $wireModifier : '' }},
            hasSeconds: @js($field->hasSeconds()),
        })"
        class="grid grid-cols-[3fr_2fr] gap-2"
        data-testid="{{ $nativeTestId }}"
    >
        <input
            type="date"
            id="{{ $nativeId }}"
            x-model="date"
            @change="commit()"
            {!! $field->getExtraInputAttributesHtml() !!}
            @if($nativeLabel) aria-label="{{ $nativeLabel }}" @endif
            @if($minBound) min="{{ \NyonCode\WireCore\Foundation\Support\DateBoundary::datePart($minBound) }}" @endif
            @if($maxBound) max="{{ \NyonCode\WireCore\Foundation\Support\DateBoundary::datePart($maxBound) }}" @endif
            @if($field->isDisabled()) disabled @endif
            @if($field->isReadOnly()) readonly @endif
            @if($field->isRequired()) @if($ariaRequiredOnly) aria-required="true" @else required @endif @endif
            class="{{ $inputClasses }}"
        />
        @include('wire-core::partials.native-select', $slotSelect + [
            'selectId' => $nativeId.'-time',
            'wireModel' => 'x-model',
            'statePath' => 'time',
            'options' => $field->getSlotOptions($state),
            'ariaLabel' => $field->getLabel(),
            'testId' => $nativeTestId.'-time',
            'extraInputAttributes' => '@change="commit()"',
        ])
    </div>
@else
    <input
        type="{{ $field->getNativeInputType() }}"
        id="{{ $nativeId }}"
        {{ $wireAttr }}="{{ $field->getWireModelAttribute() }}"
        {!! $field->getExtraInputAttributesHtml() !!}
        data-testid="{{ $nativeTestId }}"
        @if($nativeLabel) aria-label="{{ $nativeLabel }}" @endif
        @if($field->getPlaceholder()) placeholder="{{ $field->getPlaceholder() }}" @endif
        @if($minBound) min="{{ $minBound }}" @endif
        @if($maxBound) max="{{ $maxBound }}" @endif
        @if($nativeStep !== null) step="{{ $nativeStep }}" @endif
        @if($field->isDisabled()) disabled @endif
        @if($field->isReadOnly()) readonly @endif
        @if($field->hasAutofocus() && ! $responsive) autofocus @endif
        @if($field->isRequired()) @if($ariaRequiredOnly) aria-required="true" @else required @endif @endif
        class="{{ $inputClasses }}"
    />
@endif

@if($responsive)</div>@endif
