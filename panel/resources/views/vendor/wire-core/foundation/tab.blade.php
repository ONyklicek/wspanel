<div
    x-data="{ index: null }"
    x-init="index = registerTab(@js($label))"
    x-show="active === index"
    x-cloak
    role="tabpanel"
    {{ $attributes }}
>
    {{ $slot }}
</div>
