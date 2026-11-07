<?php

declare(strict_types=1);

return [
    'yes' => 'Yes',
    'no' => 'No',
    'search' => 'Search...',
    'select_all' => 'Select all',
    'deselect_all' => 'Deselect all',
    'deselect' => 'Deselect',
    'no_results' => 'No results found',
    'loading' => 'Loading...',
    'create_option' => 'Create option',
    'edit_option' => 'Edit option',
    'create' => 'Create',
    'save' => 'Save',
    'cancel' => 'Cancel',

    // Shared editor toolbar vocabulary: TiptapEditor, RichEditor and
    // MarkdownEditor all title their buttons from these keys, so the three
    // editors read alike in every locale and a reworded button is reworded once.
    'editor' => [
        'bold' => 'Bold',
        'italic' => 'Italic',
        'underline' => 'Underline',
        'strike' => 'Strikethrough',
        'code' => 'Inline code',
        'highlight' => 'Highlight',
        'heading' => 'Heading :level',
        'bullet_list' => 'Bullet list',
        'ordered_list' => 'Numbered list',
        'blockquote' => 'Blockquote',
        'code_block' => 'Code block',
        'link' => 'Link',
        'image' => 'Image',
        'table' => 'Table',
        'align_left' => 'Align left',
        'align_center' => 'Align center',
        'align_right' => 'Align right',
        'undo' => 'Undo',
        'redo' => 'Redo',

        // MarkdownEditor's write/preview tabs.
        'write' => 'Write',
        'preview' => 'Preview',

        // Browser prompt() titles — resolved in PHP and handed to the editor JS
        // through the Alpine config, so they follow the app locale too.
        'link_url' => 'Link URL',
        'image_url' => 'Image URL',
    ],

    // MoneyInput's own validation messages: the amount lives behind the
    // grouping separators, so the bounds are reported back in the format the
    // user typed rather than as a bare float.
    // DateTimePicker's bounds, held on the server as well as drawn by the
    // picker — a typed value or a phone's own wheel can step outside them.
    'date' => [
        'invalid' => 'The :attribute is not a valid date.',
        'min' => 'The :attribute must not be earlier than :min.',
        'max' => 'The :attribute must not be later than :max.',
        'disabled' => 'The selected :attribute is not available.',
        'min_time' => 'The time in :attribute must not be before :min.',
        'max_time' => 'The time in :attribute must not be after :max.',
    ],

    'money' => [
        'invalid' => 'The :attribute must be an amount.',
        'min' => 'The :attribute must be at least :min.',
        'max' => 'The :attribute must not be greater than :max.',
    ],

    // PhoneInput's validation messages. The number is checked in three steps —
    // is it international at all, is its prefix one the field offers, does the
    // national part have the digits that country issues — and each reads back
    // as its own message.
    'phone' => [
        'invalid' => 'The :attribute must be a valid international phone number.',
        'country' => 'The :attribute must be a number from one of the offered countries.',
        'length' => 'The :attribute must have between :min and :max digits after the dialling code.',
    ],

    // SignaturePad's on-canvas chrome.
    'signature' => [
        'hint' => 'Sign here',
        'clear' => 'Clear',
    ],

    // DateRangePicker: the two ends, and the periods it offers in one click.
    'range' => [
        'from' => 'From',
        'to' => 'To',
        'today' => 'Today',
        'this_week' => 'This week',
        'this_month' => 'This month',
        'last_30_days' => 'Last 30 days',
        'this_year' => 'This year',
    ],

    // The mobile wheel picker (->touchOnMobile()): its columns and its buttons.
    'wheel' => [
        'hours' => 'Hours',
        'minutes' => 'Minutes',
        'day' => 'Day',
        'month' => 'Month',
        'year' => 'Year',
        'today' => 'Today',
        'done' => 'Done',
        'cancel' => 'Cancel',
        'clear' => 'Clear',
    ],

    // The desktop picker panel: its header, its clock columns and its footer.
    'picker' => [
        'previous' => 'Previous',
        'next' => 'Next',
        'choose_month' => 'Choose month',
        'choose_year' => 'Choose year',
        'hours' => 'Hours',
        'minutes' => 'Minutes',
        'seconds' => 'Seconds',
        'today' => 'Today',
        'now' => 'Now',
        'clear' => 'Clear',
        'done' => 'Done',
        'cancel' => 'Cancel',
        'start' => 'Start',
        'end' => 'End',
        'presets' => 'Periods',
        'pick_end' => 'Pick the end of the period',
    ],
];
