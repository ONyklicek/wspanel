@php
    use Illuminate\Database\Eloquent\Model;
    use NyonCode\WireCore\Actions\Contracts\RendersAsButton;

    assert($action instanceof RendersAsButton);
    /** @var Model|null $record */

    // The action resolves its own render state (classes, icon, label, disabled,
    // extra attributes) plus the host-supplied click expression via the click
    // resolver ($click). Core never hardcodes a table/form Livewire method.
    $data = $action->toButtonRenderArray($record ?? null, $click ?? null);

    // An explicit wireClick prop (a custom host handler) overrides the
    // resolver-derived expression; the same expression is reused as the
    // wire:loading target so the spinner gates on the exact click.
    $wireClickAction = $wireClick ?? $data['wireClick'];
    $wireModifiers = $wireClickModifiers ?? $data['wireModifiers'] ?? '';

    // A key, but only where the button belongs to a record. A row's action list
    // genuinely varies per record — an action can be non-executable for one row
    // and not the next — so the morph has to pair these by identity rather than
    // by position, or a row whose first action is hidden pairs its second against
    // its neighbour's first. Livewire's `@foreach` markers do that job today; the
    // key is what lets the row loop stop paying 702 B a row for them.
    //
    // Record-less surfaces (a header action, a bulk action, an empty state) render
    // exactly the same set every time and are left alone.
    $recordKeyForMorph = isset($record) && $record instanceof Model
        ? 'act-'.$record->getKey().'-'.$action->getName()
        : null;
@endphp

@if($action->isHidden($record ?? null))
    {{-- Hidden actions render nothing. --}}
@elseif($data['url'])
    <a
            @if($recordKeyForMorph) wire:key="{{ $recordKeyForMorph }}" @endif
            href="{{ $data['url'] }}"
            @if($data['target']) target="{{ $data['target'] }}" @endif
            @if($data['navigate'] ?? false) wire:navigate @endif
            class="{{ $data['classes'] }}"
            data-testid="action-{{ $action->getName() }}"
            @if($action->getLabel($record ?? null)) aria-label="{{ $action->getLabel($record ?? null) }}" @endif
            @if($data['tooltip']) title="{{ $data['tooltip'] }}" @endif
            @if($data['shortcutLabel']) data-shortcut="{{ $data['shortcutLabel'] }}"@endif
            @foreach($data['extraAttributes'] as $attr => $val)
                {{ $attr }}="{{ $val }}"
            @endforeach
    >
        @include('wire-core::actions.partials.button-content', ['data' => $data])
    </a>
@else
    <button
            @if($recordKeyForMorph) wire:key="{{ $recordKeyForMorph }}" @endif
            type="button"
            wire:click{{ $wireModifiers }}="{{ $wireClickAction }}"
            {{-- Targets the host's modal island, so opening this action returns the
                 modal instead of the whole table. Only for actions that open one:
                 a targeted call makes Livewire skipRender() everything else, which
                 would be wrong for an action that mutates rows. --}}
            @if($action->hasModal()) wire:island="action-modals" @endif
            class="{{ $data['classes'] }}"
            data-testid="action-{{ $action->getName() }}"
            @if($action->getLabel($record ?? null)) aria-label="{{ $action->getLabel($record ?? null) }}" @endif
            @if($data['tooltip']) title="{{ $data['tooltip'] }}" @endif
            @if($data['disabled']) disabled @endif
            @if($data['shortcutAlpine'])
                x-on:keydown.{{ $data['shortcutAlpine'] }}.window.prevent="$el.click()"
            @endif
            @if($data['shortcutLabel']) data-shortcut="{{ $data['shortcutLabel'] }}"@endif
            @foreach($data['extraAttributes'] as $attr => $val)
                {{ $attr }}="{{ $val }}"
            @endforeach
    >
        @if($data['showLoading'])
            {{-- Loading spinner. The wrapping span carries the per-record wire:target
                 so the spinner markup itself stays record-invariant and is resolved
                 once per request (see Foundation\View\Primitives). --}}
            <span wire:loading wire:target="{{ $wireClickAction }}">{!! $data['spinnerHtml'] !!}</span>
            <span wire:loading.remove wire:target="{{ $wireClickAction }}" class="inline-flex items-center gap-1.5">
                @include('wire-core::actions.partials.button-content', ['data' => $data])
            </span>
            @if($data['loadingText'])
                <span wire:loading wire:target="{{ $wireClickAction }}">{{ $data['loadingText'] }}</span>
            @endif
        @else
            @include('wire-core::actions.partials.button-content', ['data' => $data])
        @endif
    </button>
@endif
