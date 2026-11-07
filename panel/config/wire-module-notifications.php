<?php

declare(strict_types=1);
use NyonCode\WireCore\Notifications\DatabaseNotification;

return [

    /*
    |--------------------------------------------------------------------------
    | The Notification Model
    |--------------------------------------------------------------------------
    |
    | wire-core writes stored notifications and owns the table; this module shows
    | them. Only the `database` driver stores anything — with the default
    | `session` driver there is nothing here to list, which the installer says.
    |
    */
    'model' => DatabaseNotification::class,

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    |
    |   own   — a signed-in user sees their own notifications (the default)
    |   all   — everyone's, which is an administrative view and wants a policy
    |
    */
    'scope' => env('WIRE_NOTIFICATIONS_SCOPE', 'own'),

    'navigation' => [
        /*
        |----------------------------------------------------------------------
        | Whether the inbox appears in the sidebar
        |----------------------------------------------------------------------
        |
        | Off, and that is the design rather than a shy default. Notifications
        | are not an entity you administer from a menu — they are an inbox, and
        | the way in is the bell: it carries the unread count, and its panel
        | links here. A permanent sidebar row for a screen you reach from a badge
        | is the row nobody reads.
        |
        | The page stays registered and routed either way, so its URL keeps
        | working and the bell's "view all" keeps finding it. Turn this on if you
        | want the row as well.
        */
        'visible' => env('WIRE_NOTIFICATIONS_IN_NAVIGATION', false),

        'group' => 'system',
        'label' => null,
        'icon' => 'outline:bell',
        'sort' => 96,
    ],

];
