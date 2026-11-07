@php
    /**
     * Canonical callout/alert surface. Consumed by Foundation\Schema\Callout, the
     * forms Alert display field and the standalone <x-wire::callout> tag so the
     * markup lives in exactly one place.
     *
     * @var string      $colorClasses  Soft surface classes (HasColor::getAlertColorClasses).
     * @var string|null $icon          Leading icon name.
     * @var string|null $heading       Bold heading line.
     * @var string      $body          Pre-rendered/escaped body HTML.
     * @var bool        $dismissible   Show a dismiss button (Alpine-toggled).
     */
    $colorClasses ??= \NyonCode\WireCore\Foundation\Concerns\HasColor::getAlertColorClasses('info');
    $icon ??= null;
    $heading ??= null;
    $body ??= '';
    $dismissible ??= false;
@endphp

<div
    @wireEl('callout')
    @if($dismissible) x-data="{ show: true }" x-show="show" x-transition @endif
    class="rounded-md border p-4 {{ $colorClasses }}"
    role="alert"
    {{-- Optional: the component this callout is rendering for, when it has one.
         The schema `Callout` is a layout element with no attributes to carry;
         the forms `Alert` is a component with `extraAttributes()`. --}}
    @isset($component) @wireExtraAttributes($component) @endisset
>
    <div class="flex">
        @if($icon)
            <div class="shrink-0 mr-3">
                {!! icon($icon, 'w-4 h-4', 'w-5 h-5') !!}
            </div>
        @endif
        <div class="flex-1 min-w-0">
            @if($heading)
                <h3 class="text-sm font-medium">{{ $heading }}</h3>
            @endif
            @if($body !== '' && $body !== null)
                <div @class(['text-sm', 'mt-1' => $heading])>{!! $body !!}</div>
            @endif
        </div>
        @if($dismissible)
            <button
                type="button"
                @click="show = false"
                data-testid="callout-dismiss" @wireEl('callout-dismiss')
                aria-label="{{ __('Dismiss') }}"
                class="ml-3 shrink-0 -mt-1 -mr-1 p-1 rounded-md hover:opacity-75 focus:outline-none"
            >
                <span class="sr-only">{{ __('Dismiss') }}</span>
                {!! icon('outline:x-mark', 'w-4 h-4', 'w-4 h-4') !!}
            </button>
        @endif
    </div>
</div>
