<?php

declare(strict_types=1);

use NyonCode\WireCore\Audit\AuditEntry;
use NyonCode\WireCore\Foundation\Icons\DefaultIconSet;
use NyonCode\WireCore\Foundation\Preferences\Drivers\DatabasePreferenceDriver;
use NyonCode\WireCore\Foundation\Preferences\Drivers\NullPreferenceDriver;
use NyonCode\WireCore\Foundation\Preferences\Drivers\SessionPreferenceDriver;

return [
    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Every package's routes are a group: `panel` (resource pages), `zones`
    | (the address above them), `tenant-entry`, and whatever the installed
    | modules bring (`tenants`, `auth-codes`). Two ways to place one (ADR 0041),
    | both through the same code:
    |
    |   routes/web.php — Route::middleware([...])->prefix('admin')
    |                        ->group(fn () => Route::wire('panel'));
    |
    |   here           — 'groups' => ['admin' => ['uses' => 'panel',
    |                        'prefix' => 'admin', 'middleware' => ['web', 'auth']]],
    |
    | An entry takes Laravel's group attributes — prefix, domain, middleware,
    | without_middleware, as, where, namespace, scope_bindings — plus `can`, the
    | group's own options (`zone`, `tenant`, `only`, `except`, …) and `routes`,
    | changes to single routes by key: ['register' => ['can' => 'tenants.create']].
    | An entry's key is the group it registers unless `uses` names one; then the
    | key is its zone. `false` or `'enabled' => false` skips it.
    |
    | Nothing here is registered by default. Groups registered here are matched
    | BEFORE everything in routes/web.php, because the framework's route file is
    | loaded first; place a group in routes/web.php to decide its order yourself.
    | A route both ways register is refused, not replaced.
    |
    */
    'routes' => [
        // What every entry starts from, over what its group ships — the panel
        // ships `['web', 'auth']`. Empty, so no group loses its own guard.
        'defaults' => [],
        'groups' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigate
    |--------------------------------------------------------------------------
    |
    | Whether links between this application's pages are followed with
    | Livewire's `wire:navigate` — the page's body is fetched and swapped in,
    | so the layout, its scroll position and the assets already loaded stay —
    | or as an ordinary full page load.
    |
    | One switch for every link the framework renders: the sidebar, breadcrumbs,
    | actions with a url, a table's record links and url columns, and the
    | redirect after a save or a delete. A link to another site, a `mailto:` or
    | one that opens in a new tab is never navigated. A single link can still
    | choose for itself with `->navigate(false)` (or `->navigate()`).
    |
    */
    'navigate' => env('WIRE_NAVIGATE', true),

    'notifications' => [
        /*
        | Which driver delivers a notification.
        |
        |   session  — flash + Livewire dispatch (the default, transient)
        |   livewire — dispatch only
        |   flasher  — hand off to a Flasher toast
        |   database  — write it down; survives the request that raised it
        |   broadcast — tell the recipient's other open pages, over websockets
        |   null      — deliver nothing
        |
        | A list picks several at once, which is usually what you want:
        | ['session', 'database'] shows the toast now and keeps it in the bell
        | for a user who was looking elsewhere.
        |
        | ['session', 'database', 'broadcast'] adds the live half: the bell on
        | every other tab and device updates as the notification is raised,
        | instead of at whatever the next round trip happens to be. `broadcast`
        | alone announces something that was never stored — pair it with
        | `database`.
        */
        'default' => env('WIRE_NOTIFICATIONS_DRIVER', 'session'),

        'database' => [
            // Laravel's own notifications shape, so an application that already
            // has that table can point this at it and read both through its own
            // Notifiable::notifications() relation.
            'table' => env('WIRE_NOTIFICATIONS_TABLE', 'wire_notifications'),

            /*
            | How long a stored notification is kept. `null` = forever.
            |
            | A notification is the one kind of row designed to stop mattering,
            | and without a period this table grows for the life of the app.
            | Two windows, because "read" and "never looked at" are different
            | claims: `read_retention_days` may clear what the user has already
            | seen sooner than the rest.
            |
            |   Schedule::command('wire-core:notifications-prune')->daily();
            */
            'retention_days' => env('WIRE_NOTIFICATIONS_RETENTION_DAYS'),
            'read_retention_days' => env('WIRE_NOTIFICATIONS_READ_RETENTION_DAYS'),
        ],

        'broadcast' => [
            /*
            | Authorize `wire-notifications.{notifiable}.{key}` for us.
            |
            | The rule is the strict one — a viewer may subscribe to their own
            | channel and to nobody else's — and it is registered only when the
            | `broadcast` driver above is actually in use, so an app that does
            | not broadcast never resolves a broadcaster at boot.
            |
            | Turn this off to write the callback yourself in routes/channels.php
            | (supervisors watching a queue, a tenancy rule of your own):
            |
            |   NotificationChannel::authorize(fn ($user, $notifiable, $key) => …);
            */
            'authorize' => env('WIRE_NOTIFICATIONS_AUTHORIZE_CHANNEL', true),
        ],

        'bell' => [
            /*
            | How many the bell's panel lists. The badge always counts them all.
            */
            'limit' => 10,
        ],
    ],

    'icons' => [
        // Key of the set in 'sets' below that is used with bare names. 'default'
        // is the bundled Heroicons set. Point it at another key (e.g. 'lucide')
        // to make that set the unprefixed base; the 'default' entry is then left
        // out, and a key 'sets' does not have is refused at boot.
        'default_set' => 'default',

        // Icon sets registered with the IconManager. Each value must be a class
        // implementing NyonCode\WireCore\Foundation\Icons\IconSet.
        //
        // The bundled 'default' set (Heroicons) is the base set and its icons are
        // used with bare names (e.g. "pencil", "user"). EVERY other set's key is a
        // REQUIRED prefix: its icons are addressed as "prefix:name" (e.g.
        // "lucide:home"), so the two never collide and resolution is deterministic.
        // Registering a non-default set without a string prefix throws.
        //
        // Sets that also implement ProvidesIconMetadata may ship stroke-based or
        // non-20x20 icons (Lucide, Feather, Heroicons outline, …) and they render
        // correctly alongside the default solid set.
        // Besides the unprefixed solid 'default' set, the framework also bundles
        // the Heroicons outline variant (24x24, stroke), always available under
        // the "outline:" prefix (e.g. "outline:x-mark"). Use outline for larger UI
        // chrome (close buttons, toolbars, pagination, empty states) and the solid
        // set for small accents. List a set below only to add a third-party set or
        // to override the "outline" prefix with a different set.
        'sets' => [
            'default' => DefaultIconSet::class,
            // 'lucide' => App\Wire\Icons\LucideIconSet::class,   // → "lucide:home"
            // 'custom' => App\Wire\Icons\MyIconSet::class,       // → "custom:logo"
        ],

        // Directories of SVG files to auto-register as icons. The icon name is
        // the file name without extension (logo.svg => "logo"). Use a string key
        // as a name prefix to namespace a folder and avoid file-name collisions
        // (e.g. 'brand' => resource_path('icons/brand') => "brand-logo"). Each
        // file keeps its own viewBox and fill/stroke styling.
        'paths' => [
            // resource_path('icons'),
            // 'brand' => resource_path('icons/brand'),
        ],

        // When true, an unknown icon name logs a warning (and still renders the
        // fallback placeholder). Handy in development to catch typos.
        'warn_missing' => env('WIRE_ICONS_WARN_MISSING', false),

        // Draw a repeated icon once. Inside a Livewire render (a component, an
        // island, a partial) every icon becomes the same <svg> holding a
        // <use href="#wi-…">, and the path data travels once per render as a
        // <symbol> — a table no longer repeats every action's icon in every row.
        // Outside a Livewire render (PDF, mail, plain Blade) nothing changes.
        //
        // Off by default, for one reason: a stylesheet of the application's own
        // that reaches INTO an icon (`.toolbar svg path { … }`) stops matching,
        // because a <use> draws its symbol in a shadow tree that selectors do
        // not cross. Inherited properties — color, fill, stroke, opacity,
        // stroke-dashoffset — still reach it, which is all the stack's own
        // markup relies on.
        'sprite' => env('WIRE_ICONS_SPRITE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Colours
    |--------------------------------------------------------------------------
    |
    | `success`, `danger`, `warning` and `info` are roles, not colours: what each
    | renders as is the hue named here, and every surface follows it — buttons,
    | badges, alerts, modal icons, toasts, table row tints, the audit timeline.
    |
    | The value is a Tailwind hue this framework already ships classes for, never
    | a class and never a CSS variable. A class named in a config file is not a
    | file Tailwind scans, so it would silently never compile; a variable would
    | need Tailwind 4 plus a token your application remembers to define. A hue
    | lands in a `match` arm whose class strings are already literal, so this
    | works on Tailwind 3 and 4 alike.
    |
    | An unrecognised hue keeps the shipped default rather than rendering grey.
    |
    | `primary` is not here: re-point `--color-primary-*` in your own `@theme`
    | block, which is a different and older mechanism.
    |
    */
    'colors' => [
        'success' => 'emerald',
        'danger' => 'red',
        'warning' => 'amber',
        'info' => 'cyan',
    ],

    // Plugins
    // #TODO add auto discover
    /*
    |--------------------------------------------------------------------------
    | Resources
    |--------------------------------------------------------------------------
    |
    | Resource classes this application owns, as a plain list. A resource binds
    | one entity to the surfaces it exposes and implements at least
    | NyonCode\WireCore\Core\Resources\Contracts\DescribesResource.
    |
    | The key lives here rather than in a component package because identity is
    | the half that needs no surface: a resource with only a form declares it
    | through wire-forms and never installs a table package.
    |
    |   'resources' => [
    |       App\Resources\OrderResource::class,
    |   ],
    |
    */
    'resources' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboards
    |--------------------------------------------------------------------------
    |
    | Dashboard classes this application owns, as a plain list. A dashboard
    | declares a page's worth of widgets and extends
    | NyonCode\WireCore\Widgets\Dashboard; `php artisan wire:dashboard Sales`
    | generates one.
    |
    | Registered separately from resources because they are different things
    | that a menu happens to list side by side — the menu, the router and the
    | search palette all read both registries through the same Catalog, and
    | neither registry knows about the other.
    |
    |   'dashboards' => [
    |       App\Dashboards\SalesDashboard::class,
    |   ],
    |
    */
    'dashboards' => [],

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    |
    | Directories to find resources and dashboards in, instead of listing
    | them one by one above — namespace => directory, the way PSR-4 maps them.
    | Every concrete class there of the right kind is registered at boot;
    | anything else in the folder (an abstract base, a trait) is passed over.
    |
    | Off until named: it is one directory listing per boot, and an application
    | that lists its resources pays nothing for it. Both paths may be used at
    | once — a class listed and discovered registers once.
    |
    |   'discover' => [
    |       'resources' => ['App\\Resources' => app_path('Resources')],
    |       'dashboards' => ['App\\Dashboards' => app_path('Dashboards')],
    |       'pages' => ['App\\Livewire\\Pages' => app_path('Livewire/Pages')],   // wire-panels
    |   ],
    |
    */
    'discover' => [
        'resources' => ['App\\Wire\\Resources' => app_path('Wire/Resources')],
        'dashboards' => ['App\\Dashboards' => app_path('Dashboards')],
        'pages' => ['App\\Livewire\\Pages' => app_path('Livewire/Pages')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy
    |--------------------------------------------------------------------------
    |
    | Off by default: most applications have one tenant, and scoping them would
    | be a WHERE clause bought for nothing. Once on it is strict — every model
    | using BelongsToTenant is constrained, and when no tenant resolves, it is
    | constrained to NOTHING rather than to everything.
    |
    | The current tenant is whatever was entered — by a tenant zone's middleware,
    | or `app(Tenancy::class)->runAs($tenant, fn () => …)` in a job or command.
    | Nothing entered means no tenant. To answer it another way, bind your own
    | resolver:
    |
    |   app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
    |       public function resolve(): int|string|null
    |       {
    |           return auth()->user()?->tenant_id;
    |       }
    |   });
    |
    */
    'tenancy' => [
        'enabled' => env('WIRE_TENANCY', false),
        'column' => env('WIRE_TENANCY_COLUMN', 'tenant_id'),

        // How tenants are kept apart: 'column' — one database, the column
        // above — 'database' — a database per tenant, below — or the name of
        // a class implementing IsolatesTenants.
        'isolation' => env('WIRE_TENANCY_ISOLATION', 'column'),

        // Database per tenant. `connection` is the one tenant-owned models
        // (BelongsToTenantDatabase) use — define it in config/database.php,
        // usually as a copy of the default one. `name` is each tenant's
        // database: `{key}` is its key, `{slug}` its route key, and for SQLite
        // it is a file path. `admin_connection` runs CREATE DATABASE; null is
        // the default connection. Tenant migrations live in `migrations`.
        'database' => [
            'connection' => 'tenant',
            'name' => 'tenant_{key}',
            'admin_connection' => null,
            'migrations' => 'database/migrations/tenant',
        ],

        // The tenant model a tenant zone finds by its route key (a slug when
        // the model says so), and the pivot table InteractsWithTenants reads
        // membership from. Only a tenant zone needs them.
        'model' => null,
        'members_table' => 'tenant_user',

        // The attribute a company is called by in the switcher; its route key
        // when that is empty.
        'label' => 'name',
    ],

    'plugins' => [
        // App\Wire\Plugins\ExamplePlugin::class,
    ],

    'modals' => [
        'default_width' => 'md',
        'slide_over_width' => 'md',
        'close_on_click_away' => true,
        'close_on_escape' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile
    |--------------------------------------------------------------------------
    |
    | How floating panels (action-group menus, dropdowns, select/date/tag
    | pickers, table filter & column-toggle panels) and action modals behave on
    | small screens. These are the GLOBAL DEFAULTS — every component can override
    | them per-instance:
    |
    |   Sheet on/off (sheet vs. classic floating dropdown):
    |     ->sheetOnMobile(true|false)          fields, filters, ActionGroup, Table
    |     :sheet-on-mobile="false"             <x-wire::dropdown>
    |
    |   Breakpoint (where the sheet/full-screen kicks in):
    |     ->mobileBreakpoint('sm'|'md'|'lg')   fields, filters, ActionGroup, Table,
    |                                          action modals (HasModal)
    |     :breakpoint="'md'"                   <x-wire::dropdown>
    |
    |   Touch-built control below the breakpoint (wheel / full-height list):
    |     ->touchOnMobile(true|false)          Select, BelongsToSelect,
    |                                          DateTimePicker, TimePicker,
    |                                          SelectFilter, TernaryFilter
    |
    |   Browser-native control below the breakpoint instead of the custom one:
    |     ->nativeOnMobile(true|false)         Select, BelongsToSelect,
    |                                          DateTimePicker, TimePicker,
    |                                          SelectFilter, TernaryFilter
    |
    | Notes: searchable Select/SelectFilter default to floating (search stays
    | usable); an explicit ->sheetOnMobile() still wins. Sheets add safe-area
    | padding, a drag-to-dismiss grabber and a focus trap automatically.
    |
    */
    'mobile' => [
        // Default: present floating panels as a bottom sheet on mobile.
        // false = classic trigger-anchored floating panel everywhere.
        'sheet' => env('WIRE_MOBILE_SHEET', true),

        // Default breakpoint below which panels present as a mobile sheet:
        //   'sm' (< 640px, phones — default)
        //   'md' (< 768px, incl. small tablets)
        //   'lg' (< 1024px, incl. tablet portrait)
        // From the breakpoint up, the classic desktop floating panel is used.
        'breakpoint' => env('WIRE_MOBILE_BREAKPOINT', 'sm'),

        // Default: keep the custom select/date/time controls on a phone too.
        // true = below the breakpoint every select and picker that has a
        // browser-native counterpart renders that instead (the phone's own
        // wheel or list); from the breakpoint up the custom control stays.
        // Per instance: ->nativeOnMobile(true|false).
        'native' => env('WIRE_MOBILE_NATIVE', false),

        // Default: no touch-built controls. true = below the breakpoint, date
        // and time pickers become a wheel and selects a full-height list sheet
        // (large rows, 16px text, swipe to dismiss) — keeping remote search,
        // create-option and disabled days, which the browser's control drops.
        // Outranks 'native'. Per instance: ->touchOnMobile(true|false).
        'touch' => env('WIRE_MOBILE_TOUCH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Log
    |--------------------------------------------------------------------------
    |
    | Configuration for the audit logging system. Records who changed what,
    | when, with old/new value diffs. Integrates with the event system.
    |
    */
    'audit' => [
        // Global on/off switch for audit logging
        'enabled' => env('WIRE_AUDIT_ENABLED', true),

        // Custom AuditEntry model (must extend NyonCode\WireCore\Audit\AuditEntry)
        'model' => AuditEntry::class,

        // User model for the user() relationship on AuditEntry
        'user_model' => env('WIRE_AUDIT_USER_MODEL', 'App\\Models\\User'),

        // Which event types to log (null = all)
        // Available: 'created', 'updated', 'deleted', 'bulk_action', 'cell_updated'
        'events' => null,

        // Columns to never log (applied globally, in addition to per-model
        // exclusions). `*` is allowed: 'billing_*' excludes the lot.
        //
        // This list ADDS to a floor the logger applies on its own — passwords,
        // anything ending in _token or _secret, two-factor columns, recovery
        // codes and api_key are never written to the trail, and emptying this
        // list does not bring them back. Editing a published config file must
        // not be able to put a credential on the audit screen.
        'exclude_columns' => [
            'password',
            'remember_token',
        ],

        // Entries older than N days are deleted by `wire-core:audit-prune`
        // (null = keep them). Nothing prunes on its own — schedule it:
        //   Schedule::command('wire-core:audit-prune')->daily();
        'retention_days' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Preferences
    |--------------------------------------------------------------------------
    |
    | Where "what this user did to this surface" is kept — a customisable
    | dashboard's widget layout today. The same store wire-table uses for a
    | user's column layout, configured separately because whether a dashboard
    | layout is worth a database row is its own question.
    |
    |   - null     : not persisted (default). A dashboard can still be marked
    |                customisable; the arrangement simply lives for the page.
    |   - session  : the user's session. No migration.
    |   - database : the `wire_preferences` table (publish + run the migration:
    |                vendor:publish --tag="wire-core::migrations")
    |
    | Point an alias at your own class to use a custom store; it implements
    | Foundation\Preferences\Contracts\PreferenceDriver.
    |
    */
    'preferences' => [
        'default' => env('WIRE_PREFERENCES_DRIVER', 'null'),
        'guest' => env('WIRE_PREFERENCES_GUEST_DRIVER', 'session'),
        'drivers' => [
            'null' => NullPreferenceDriver::class,
            'session' => SessionPreferenceDriver::class,
            'database' => DatabasePreferenceDriver::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tours
    |--------------------------------------------------------------------------
    |
    | Where "which walkthroughs this user has already seen" is kept. The same
    | store as above, with its own default on purpose: a table that forgets a
    | hidden column is an annoyance, while a tour on a store that forgets is a
    | walkthrough interrupting the same person on every page load, for ever.
    |
    | So this defaults to `session` — the worst untouched-config behaviour is
    | "once per session" rather than "every time". An application that wants
    | "once, ever" sets `database` and runs the migration:
    |
    |   vendor:publish --tag="wire-core::migrations"
    |
    | Nothing is registered by default; the framework ships no tour of its own.
    |
    */
    'tours' => [
        /*
        |----------------------------------------------------------------------
        | Postponements
        |----------------------------------------------------------------------
        |
        | How many times a tour's welcome block may be answered with "Later"
        | before it stops asking and counts as seen. Each "Later" puts the tour
        | down for the session; reaching this number records it the way skipping
        | does. Zero removes the button, leaving a welcome that can only be
        | started. A tour may override this with `->postpone(int $times)`.
        |
        */

        'postpone' => env('WIRE_TOURS_POSTPONE', 3),

        'preferences' => [
            'default' => env('WIRE_TOURS_DRIVER', 'session'),
            'guest' => env('WIRE_TOURS_GUEST_DRIVER', 'session'),
            'drivers' => [
                'null' => NullPreferenceDriver::class,
                'session' => SessionPreferenceDriver::class,
                'database' => DatabasePreferenceDriver::class,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Density
    |--------------------------------------------------------------------------
    |
    | How much room the interface gives itself: `normal` (the shipped spacing)
    | or `compact`.
    |
    | Compact is three changes, not one, and the second and third exist because
    | the first over- and under-reaches. `--spacing` drives `h-16` and `w-4` as
    | surely as `px-3`, so the top bar and the icons are pinned back to their
    | shipped sizes; and `@tailwindcss/forms` writes control padding as a literal
    | that no token can reach, so it is addressed by name. All of it is in
    | `wire-core::partials.density`, which the shell puts in the head.
    |
    | Type is untouched — font size reads its own tokens — so this tightens the
    | chrome and leaves the words alone.
    |
    | Requires Tailwind 4: on 3 the utilities are compiled values with no
    | variable to move, so this sets a property nothing reads.
    |
    */
    'density' => env('WIRE_DENSITY', 'normal'),

    /*
    |--------------------------------------------------------------------------
    | Shape
    |--------------------------------------------------------------------------
    |
    | How the corners are cut: `rounded` (the shipped corners) or `sharp`.
    |
    | Unlike density, this has no per-person switch. Density is a working
    | preference — one person wants more rows on screen than another. Shape is
    | identity, and an admin that is round for one colleague and square for the
    | next is two products rather than one respected preference.
    |
    | Sharp is two rules: the radius tokens go to zero, and the pills are squared
    | by name because `rounded-full` reads no token. Avatars stay round on
    | purpose — see `wire-core::partials.shape`.
    |
    | Requires Tailwind 4, for the same reason density does.
    |
    */
    'shape' => env('WIRE_SHAPE', 'rounded'),

];
