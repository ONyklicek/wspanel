@php
    $statePath = $field->getStatePath();
    $hasError = $errors->has($statePath);
    // Through the canonical owner, resolved against the grid this field was
    // told it is in. The local map this replaced stopped at two columns — a
    // `columnSpan(3)` or `(4)` emitted no class at all and the field stayed one
    // column wide, and `columnSpanFull()` was drawn as two columns rather than
    // the row. Both are declarations the API accepts and the docs describe.
    $spanClass = $field->getColumnSpanClass();
@endphp

<div
    wire:key="field-{{ $statePath }}"
    {{-- The anchor a field partial replaces. Emitted only where the form asked
         for it, the way the table gates its row anchors: an anchor costs an
         attribute, and `partials.js` errors on a duplicate name, so a form that
         will never send one should not carry it. --}}
    @if($fieldPartials ?? false) wire:partial="field-{{ $statePath }}" @endif
    data-testid="form-field-{{ $statePath }}"
    @wireEl('form-field')
    data-field="{{ $statePath }}"
    @class(['wire-field relative', $spanClass])
    @wireExtraAttributes($field)
>

    {{-- $hideLabel is this wrapper's own override (a layout that already names the
         field); the rest of the rule lives on HasLabel so every surface agrees. --}}
    @if($field->hasVisibleLabel() && !($hideLabel ?? false))
        @php
            // Below the breakpoint the phone may see another element than the
            // desktop (->nativeOnMobile(), ->touchOnMobile()); the label follows
            // it, or a tap on it lands on a display:none control.
            $labelTargets = \NyonCode\WireCore\Foundation\Support\MobileSheet::labelTargets(
                $field->getId(),
                method_exists($field, 'mobileControlIdFor') ? $field->mobileControlIdFor($field->getId()) : null,
                method_exists($field, 'getMobileBreakpoint') ? $field->getMobileBreakpoint() : null,
                'block',
            );
        @endphp
        @foreach($labelTargets as $labelFor => $labelVisibility)
        <label for="{{ $labelFor }}" @class([$labelVisibility, 'text-sm font-medium text-gray-700 dark:text-gray-300 mb-1'])>
            {{ $field->getLabel() }}
            @if($field->isRequired())
                <span class="text-red-600 dark:text-red-400">*</span>
            @endif
        </label>
        @endforeach
    @endif

    @php
        use NyonCode\WireCore\Foundation\Concerns\HasColor;

        $hintAction = method_exists($field, 'getHintAction') ? $field->getHintAction() : null;
        $hintText = method_exists($field, 'getHint') ? $field->getHint() : null;
        // hintIcon()/hintColor() are part of the hint vocabulary; the wrapper used
        // to render only the text, so both setters were silent no-ops.
        $hintIcon = method_exists($field, 'getHintIcon') ? $field->getHintIcon() : null;
        $hintColor = method_exists($field, 'getHintColor') ? $field->getHintColor() : null;
        $hintClass = $hintColor !== null
            ? HasColor::getTextColorClasses($hintColor)
            : 'text-gray-500 dark:text-gray-400';
    @endphp
    @if($hintText || $hintAction)
        <div class="mb-1 flex items-center justify-between gap-2">
            <p class="inline-flex items-center gap-1 text-xs {{ $hintClass }}">
                @if($hintIcon)
                    {!! icon($hintIcon, 'w-3.5 h-3.5', 'shrink-0') !!}
                @endif
                {{ $hintText }}
            </p>
            @if($hintAction)
                <button
                    type="button"
                    wire:click="callFieldAction('{{ $field->getStatePath() }}', '{{ $hintAction->getName() }}')"
                    wire:loading.attr="disabled"
                    wire:target="callFieldAction"
                    data-testid="field-action-{{ $statePath }}-{{ $hintAction->getName() }}"
                    @if($hintAction->getLabel()) aria-label="{{ $hintAction->getLabel() }}" @endif
                    @if($hintAction->getTooltip()) title="{{ $hintAction->getTooltip() }}" @endif
                    class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300"
                >
                    @if($hintAction->getIcon())
                        {!! icon($hintAction->getIcon(), 'w-4 h-4', 'w-3.5 h-3.5') !!}
                    @endif
                    @unless($hintAction->isHideLabel())
                        <span>{{ $hintAction->getLabel() }}</span>
                    @endunless
                </button>
            @endif
        </div>
    @endif
