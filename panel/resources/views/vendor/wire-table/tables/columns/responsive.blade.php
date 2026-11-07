{{-- Responsive cell wrapper: separate mobile/desktop markup at a breakpoint. --}}
@php
    /** @var string $mobileClass */
    /** @var string $desktopClass */
    /** @var string $mobileContent */
    /** @var string $desktopContent */
@endphp
<span class="{{ $mobileClass }}">{!! $mobileContent !!}</span><span class="{{ $desktopClass }}">{!! $desktopContent !!}</span>
