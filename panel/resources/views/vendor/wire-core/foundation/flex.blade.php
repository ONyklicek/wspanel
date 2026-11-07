<div {{ $attributes->class([
    'flex flex-col',
    $rowClass,
    $gapClass,
    $justifyClass,
    $alignClass,
    'flex-wrap' => $wrap,
    '[&>*]:flex-1 [&>*]:min-w-0' => $grow,
]) }}>
    {{ $slot }}
</div>
