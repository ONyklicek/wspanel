<?php

declare(strict_types=1);

return [
    'date_format' => 'd.m.Y',
    'time_format' => 'H:i',
    'datetime_format' => 'd.m.Y H:i',
    'first_day_of_week' => 1,

    // How MoneyInput writes an amount when a field does not say otherwise. The
    // currency is spelling-sensitive: 'Kč' renders whole crowns, the ISO code
    // 'CZK' renders hellers.
    'money' => [
        'currency' => 'CZK',
        'decimal_separator' => ',',
        'thousands_separator' => ' ',
    ],

    // PhoneInput's offer. An empty country list offers the whole dialling-code
    // table; naming a few is what most applications want, and it is also the
    // validation — a number from a country the field does not offer is refused.
    'phone' => [
        'countries' => [],
        'default_country' => null,
    ],

    'file_upload' => [
        'disk' => env('WIRE_FORMS_UPLOAD_DISK', 'public'),
        'directory' => 'uploads',
    ],

    'rich_editor' => [
        'toolbar' => [
            'bold', 'italic', 'underline', 'strike',
            'h2', 'h3',
            'bulletList', 'orderedList',
            'link', 'blockquote', 'codeBlock',
            'undo', 'redo',
        ],
    ],
];
