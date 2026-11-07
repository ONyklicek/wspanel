<div
    x-data="{ index: null }"
    x-init="index = registerStep(@js($label))"
    x-show="current === index"
    x-cloak
    {{ $attributes }}
>
    {{ $slot }}
</div>
