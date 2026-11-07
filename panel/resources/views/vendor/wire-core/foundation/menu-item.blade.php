{{-- One row of a user menu, from NyonCode\WireCore\Foundation\View\MenuItem.

     An `href` makes it a link and no `href` makes it a button, because the two
     things a user menu holds are exactly that: somewhere to go, and a form to
     submit. Nothing here knows about auth or about a profile — the packages that
     own those contribute rows, and this is what a row looks like. --}}
@php($tag = $href ? 'a' : 'button')

<{{ $tag }}
    @wireEl('menu-item')
    @if ($href)
        href="{{ $href }}"
        {{-- Unless the caller already wrote it: a layout that predates the switch
             passes `wire:navigate` itself, and the attribute twice is noise. --}}
        @unless ($attributes->has('wire:navigate')) @wireNavigate($href, $navigate) @endunless
    @else
        type="{{ $type }}"
    @endif
    {{ $attributes->merge([
        'class' => 'group flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60',
    ]) }}
>
    @if ($icon)
        {!! icon($icon, 'h-4 w-4 shrink-0 text-gray-400 group-hover:text-gray-500 dark:group-hover:text-gray-300') !!}
    @endif

    {{ $slot }}
</{{ $tag }}>
