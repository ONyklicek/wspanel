<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The Frame
    |--------------------------------------------------------------------------
    |
    | Which layout the signed-out screens render inside. They ship no frame of
    | their own, deliberately: a login page that does not look like the panel it
    | leads into is the one screen where a mismatch is noticed, and the shell
    | already owns a frame for exactly this — `wire-admin::auth-layout`, the head
    | and the card with none of the navigation.
    |
    | `auto` uses the application's own auth layout when there is one — the
    | component the installer writes — and the shell's frame otherwise, when the
    | shell is installed. An application with a frame elsewhere names it here:
    |
    |     'layout' => 'components.layouts.guest',
    |
    | With neither — no shell, no name — rendering a screen raises an exception
    | that says this, rather than a missing-component error one layer down.
    |
    */
    'layout' => env('WIRE_AUTH_LAYOUT', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | The Screens
    |--------------------------------------------------------------------------
    |
    | Whether this package answers Fortify's view callbacks. Fortify is headless
    | — it ships the routes, the credential check, the throttling, the reset
    | tokens and the two-factor challenge, and asks the application for the
    | markup — so this is the whole of what the package does on the way in.
    |
    | Which screens exist is Fortify's question, not this one: registration,
    | password reset, email verification and two-factor are its `features`, and
    | a screen for a feature that is off is never routed to. Turn this off to
    | keep the package for its user-menu entry alone, or while migrating from
    | views you already had.
    |
    | An application that wants six of the seven registers its own in a provider
    | of its own — application providers boot after package ones, so the last
    | `Fortify::loginView()` wins and it is yours.
    |
    */
    'views' => env('WIRE_AUTH_VIEWS', true),

    /*
    |--------------------------------------------------------------------------
    | The Way Out
    |--------------------------------------------------------------------------
    |
    | Whether to put "Sign out" in the shell's user menu. It posts to Fortify's
    | own logout route, so the session invalidation and the token regeneration
    | stay where they are maintained.
    |
    | On by default because the alternative was every application hand-writing
    | the same form into a layout slot: an admin whose menu has no way out reads
    | as unfinished, and it is not a decision anyone was making deliberately.
    |
    */
    'user_menu' => env('WIRE_AUTH_USER_MENU', true),

    /*
    |--------------------------------------------------------------------------
    | One-Time Codes
    |--------------------------------------------------------------------------
    |
    | Four things a six-digit code mailed to somebody can stand in for, and each
    | one is its own switch because each one is a different decision about the
    | same trade: a code is only as strong as the inbox it lands in (ADR 0037).
    |
    |   login           sign in with a code and no password at all. A user with
    |                   an authenticator app is still challenged for it — a code
    |                   to an inbox must not be the way around a second factor.
    |   second_factor   a code after a correct password, for people who have no
    |                   authenticator app. **Needs Fortify's two-factor feature
    |                   to be on**: the pipe that sends the code is the contract
    |                   Fortify only puts in its login pipeline when it is, and
    |                   with the feature off no code is ever sent. `php artisan
    |                   about` reports that state rather than printing "off".
    |   verify_email    confirm an address by typing a code. The signed link
    |                   Fortify mails keeps working beside it.
    |   reset_password  the reset mail carries a code instead of a link. The
    |                   code is what expires — `expires` below, not the broker's
    |                   `auth.passwords.*.expire` — and is used once; a correct
    |                   one asks the broker for a fresh token on the spot, which
    |                   replaces any reset link mailed before it.
    |
    | Everything is off until it is switched on: an installation that says
    | nothing here gets no new routes and no new mail.
    |
    | Who gets the mailed second factor is the user model's answer first: a model
    | implementing `Contracts\ReceivesLoginCodes` decides per user, and one that
    | does not leaves the switch below answering for everybody without a
    | confirmed authenticator app.
    |
    */
    'codes' => [

        'login' => env('WIRE_AUTH_CODE_LOGIN', false),
        'second_factor' => env('WIRE_AUTH_CODE_SECOND_FACTOR', false),
        'verify_email' => env('WIRE_AUTH_CODE_VERIFY_EMAIL', false),
        'reset_password' => env('WIRE_AUTH_CODE_RESET_PASSWORD', false),

        /*
        | How many digits, and how long they are worth anything. Ten minutes is
        | long enough to read a mail on a phone and type it on a laptop, and
        | short enough that a code left open in an inbox is not a spare key.
        */
        'length' => env('WIRE_AUTH_CODE_LENGTH', 6),
        'expires' => env('WIRE_AUTH_CODE_EXPIRES', 10),

        /*
        | Wrong guesses before the code itself is thrown away, and the seconds a
        | "send it again" button waits before it does anything. The first is what
        | makes six digits acceptable — the route throttle counts an address and
        | an IP, and neither of those is the thing being guessed. The second
        | stops a leaned-on button mailing five codes of which four are dead.
        */
        'attempts' => env('WIRE_AUTH_CODE_ATTEMPTS', 5),
        'resend_after' => env('WIRE_AUTH_CODE_RESEND_AFTER', 60),

        /*
        | The rate limiter on every route that takes digits or sends a mail, in
        | Laravel's `attempts,minutes` form. Its own rather than Fortify's login
        | limiter: a mistyped code should not spend the budget that belongs to
        | the password form.
        */
        'throttle' => env('WIRE_AUTH_CODE_THROTTLE', '6,1'),

        /*
        | Where the codes live. Hashed, one row per purpose and identifier, and
        | swept by expiry — an application that would rather keep them somewhere
        | else binds `Contracts\OneTimeCodes` to its own store instead.
        */
        'table' => env('WIRE_AUTH_CODE_TABLE', 'wire_auth_one_time_codes'),

    ],

];
