<?php

declare(strict_types=1);
use NyonCode\WireCore\Foundation\Preferences\Drivers\DatabasePreferenceDriver;
use NyonCode\WireCore\Foundation\Preferences\Drivers\NullPreferenceDriver;
use NyonCode\WireCore\Foundation\Preferences\Drivers\SessionPreferenceDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Table Settings
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'per_page' => 10,
        'per_page_options' => [10, 25, 50, 100],
        'searchable' => true,
        'sortable' => true,
        'hoverable' => true,
        'striped' => false,

        /*
        | The desktop gesture layer: keyboard grid navigation, Shift-ranges, the
        | drag sweep down the checkbox column, the right-click row menu, the `?`
        | shortcut help and the fill handle.
        |
        | null keeps the shipped default, where the two loudest — keyboard
        | navigation and the drag sweep — are OFF until a table asks with
        | Table::gestures(). Set true to make every table an application, false
        | to allow nothing at all, or list capabilities to mix:
        |
        |   'gestures' => ['keyboard' => true, 'drag_select' => false],
        |
        | A per-table gestures() always wins over this.
        */
        'gestures' => null,

        /*
        | A table stacked on mobile is two renderings of every record — the table
        | and a card each — of which CSS shows one. With this on, a request that
        | carries the `wire_viewport` cookie (written by the table's viewport
        | script) gets only the half that browser shows; one without it still
        | gets both. Crossing the breakpoint re-renders the table once. Turn it
        | off per table with Table::renderVisibleLayoutOnly(false).
        */
        'visible_layout_only' => true,

        /*
        | What a record marked inactive with Table::rowInactive() looks like and
        | what it still permits, project-wide. null keeps the shipped defaults:
        | the row is dimmed and tinted gray but not struck through, and inline
        | editing is locked (refused server-side too) while its actions, its
        | checkbox and a record click stay live.
        |
        |   'inactive_rows' => [
        |       'strikethrough' => true,     // strike the row's text
        |       'dim' => true,               // mute it
        |       'color' => 'danger',         // tint it through the row-tint owner
        |       'editing' => false,          // inline editing on an inactive row
        |       'selectable' => true,        // may it be ticked
        |       'actions' => true,           // are its row actions operable
        |   ],
        |
        | A per-table rowInactive(..., fn (InactiveRow $row) => ...) always wins
        | over this.
        */
        'inactive_rows' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | TextInputColumn
    |--------------------------------------------------------------------------
    */
    'text_input' => [
        'save_on_blur' => true,
        'save_on_enter' => true,
        'live_validation' => false,
        'live_debounce' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Driver
    |--------------------------------------------------------------------------
    |
    | Default notification driver class.
    | Built-in options:
    |   - \NyonCode\WireCore\Notifications\Drivers\SessionDriver::class
    |   - \NyonCode\WireCore\Notifications\Drivers\LivewireEventDriver::class
    |   - \NyonCode\WireCore\Notifications\Drivers\FlasherDriver::class
    |
    */
    'notification_driver' => null, // null = the driver wire-core.notifications.default names

    /*
    |--------------------------------------------------------------------------
    | Per-user Table Preferences
    |--------------------------------------------------------------------------
    |
    | Where a table remembers each user's column layout when it opts in with
    | Table::rememberColumns('key'). 'default' is used for signed-in users,
    | 'guest' for unauthenticated visitors (so a database-backed default can
    | still fall back to per-session memory for guests).
    |
    | Built-in drivers:
    |   - null     : do not persist (column toggles last only for the request)
    |   - session  : store in the session (no migration needed)
    |   - database : store in the `wire_preferences` table (publish + run the
    |                migration: vendor:publish --tag="wire-core::migrations")
    |
    | Point an alias at your own class to use a custom store.
    |
    */
    'preferences' => [
        'default' => env('WIRE_TABLE_PREFERENCES_DRIVER', 'null'),
        'guest' => env('WIRE_TABLE_PREFERENCES_GUEST_DRIVER', 'session'),
        'drivers' => [
            'null' => NullPreferenceDriver::class,
            'session' => SessionPreferenceDriver::class,
            'database' => DatabasePreferenceDriver::class,
        ],
    ],

];
