<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The User Model
    |--------------------------------------------------------------------------
    |
    | The application's, not the package's. A module that brought its own users
    | table would be unusable in every application that already has one — which
    | is all of them — so this points at what you already have.
    |
    */
    'model' => env('WIRE_USERS_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Fields
    |--------------------------------------------------------------------------
    |
    | Which columns the list and the form work with. Kept here rather than
    | guessed from the schema: a `users` table is the one table every
    | application has changed.
    |
    */
    'fields' => [
        'name' => 'name',
        'email' => 'email',
        'password' => 'password',
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | Role management appears when `nyoncode/laravel-permission-extended` is
    | installed and the user model carries its `HasRoles` trait. `auto` decides
    | that by looking; true and false answer it for you.
    |
    | That package, and not the `spatie/laravel-permission` it is built on: the
    | role screens here assume its wildcard matching, its super-admin gate and
    | its permission-change events, so a model on bare Spatie is deliberately not
    | detected. Set this to `true` to overrule that.
    |
    | Authorization itself never goes through this: every check in this stack is
    | `Gate::allows()`, which the package registers into. This switch is about
    | the management screens, not about who may see them.
    |
    */
    'roles' => env('WIRE_USERS_ROLES', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | The administrator role
    |--------------------------------------------------------------------------
    |
    | The role of an administrator across the whole application: ordinary
    | permissions, assigned globally so they count in every team. Unlike the
    | super-admin (`permission-extended.super_admin_role`) it bypasses nothing —
    | but it is the role that hands out the others, so only a super-admin may
    | change it on the role screens. Null when the application has no such role.
    |
    */
    'admin_role' => env('WIRE_USERS_ADMIN_ROLE', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Re-verify A Changed Address
    |--------------------------------------------------------------------------
    |
    | A verified flag belongs to the address it was granted for. Fortify's own
    | `UpdateUserProfileInformation` nulls `email_verified_at` and mails a fresh
    | notification when somebody edits their address; this module replaces that
    | action with its own form, so it restates the rule rather than losing it.
    |
    | Without this, a person could type an address they do not control and stay
    | flagged verified on it — and anything behind Laravel's `verified`
    | middleware, or any policy asking `hasVerifiedEmail()`, would then apply to
    | an address nobody had proven.
    |
    | It is narrow on purpose: it fires only when the address actually changed,
    | and it stands aside whenever the same save writes `email_verified_at`
    | itself. A seeder, a migration backfill, or an admin tool marking an address
    | verified has said what it wants, and this does not overrule it.
    |
    | Turn it off where an application clears the flag in its own way.
    |
    */
    'reverify_on_email_change' => env('WIRE_USERS_REVERIFY_ON_EMAIL_CHANGE', true),

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    |
    | The abilities the user and role screens require. Each becomes Laravel's own
    | `can:` middleware on the route and hides the button that leads there, from
    | this single line — nothing here re-implements the check, so Gate,
    | spatie/laravel-permission and permission-extended all answer it the way
    | they answer every other check in this framework.
    |
    | Unlike every other module here these default to a real ability rather than
    | to null, and the reason is what these particular screens do: the user edit
    | form sets other people's passwords and assigns roles, so a screen that is
    | open to whoever the panel's `auth` let through is a screen that turns any
    | account into an administrator. Defaulting to null would have been the
    | quieter choice and the wrong one — the failure is silent, and it looks
    | exactly like a working panel.
    |
    | So this fails closed: an installation with no such ability defined gets 403
    | until it defines one, which is a visible problem with an obvious fix. On
    | `nyoncode/laravel-permission-extended` a super-admin passes regardless,
    | through that package's own `Gate::before`.
    |
    | Set any of them to null to leave that screen open to anyone the panel's own
    | middleware already admitted — the pre-2.0 behaviour, and yours to choose.
    |
    | The profile page is deliberately absent: it is the signed-in person's own
    | account, it resolves the record from `Auth::user()` rather than the URL, and
    | putting an administrative ability in front of it would lock every user out
    | of their own password and two-factor settings.
    |
    */
    'permissions' => [
        'users' => [
            'viewAny' => 'users.viewAny',
            'view' => 'users.view',
            'create' => 'users.create',
            'update' => 'users.update',
        ],

        'roles' => [
            'viewAny' => 'roles.viewAny',
            'view' => 'roles.view',
            'create' => 'roles.create',
            'update' => 'roles.update',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Avatars
    |--------------------------------------------------------------------------
    |
    | A picture for the person, stored as a path in a column on your own users
    | table. `auto` looks for the column and shows the upload where it exists,
    | which means an application that has not migrated one sees initials and no
    | broken field.
    |
    | The column is read through the HasAvatar contract in wire-core, so a value
    | that is already a URL — Gravatar, an identity provider — is drawn as it
    | stands and nothing here has to know that.
    |
    */
    'avatar' => [
        'enabled' => env('WIRE_USERS_AVATARS', 'auto'),
        'column' => 'avatar_path',
        'disk' => env('WIRE_USERS_AVATAR_DISK', 'public'),
        'directory' => 'avatars',
    ],

    /*
    |--------------------------------------------------------------------------
    | The Profile Page
    |--------------------------------------------------------------------------
    |
    | Which cards the signed-in user's own page carries. Each one is a component
    | in its own right — an application that wants the password card on a page
    | of its own mounts it there and turns it off here.
    |
    | `browser_sessions` lists where the account is signed in only on the
    | `database` session driver — nothing else knows whose a session is. Its
    | button signs the other devices out on any driver.
    |
    | `delete_account` is off by default, and deliberately: an admin panel where
    | the only administrator can remove themselves in two clicks is a support
    | ticket waiting to happen. Turn it on where accounts are self-service.
    |
    */
    'profile' => [
        'password' => true,
        'two_factor' => true,
        'passkeys' => true,
        'browser_sessions' => true,
        'delete_account' => env('WIRE_USERS_DELETE_ACCOUNT', false),

        // Whether the shell's user menu gets a link to this page. On, because
        // the alternative was every application writing the link into a layout
        // slot by hand — and a profile page nobody can find is a page nobody
        // uses. Off where the application puts the link somewhere of its own.
        'menu_item' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication
    |--------------------------------------------------------------------------
    |
    | Laravel Fortify owns two-factor here — the secrets, the TOTP window, the
    | recovery codes, the challenge on the way in. What this module adds is the
    | card on the profile page that drives Fortify's own actions, so a panel
    | gets the feature without a second implementation of it.
    |
    | `auto` shows the card when Fortify is installed and its two-factor feature
    | is enabled; true and false answer for you. See docs/core/teams-and-2fa.md
    | for the four lines an application writes to switch it on.
    |
    */
    'two_factor' => env('WIRE_USERS_TWO_FACTOR', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Passkeys
    |--------------------------------------------------------------------------
    |
    | The same arrangement as two-factor, one layer newer: `laravel/passkeys`
    | behind Fortify's `Features::passkeys()` owns the WebAuthn ceremony, the
    | credential rows, the routes and the browser client — this module adds the
    | card that lists a person's keys, registers another and removes one.
    |
    | `auto` shows the card where the packages are installed and the feature is
    | on; true and false answer for you. The card is drawn but says what is
    | missing when the user model has not taken `PasskeyAuthenticatable` yet,
    | because that is the one line an application has to write itself and its
    | absence is otherwise silent — every route answers and the key belongs to
    | nobody.
    |
    */
    'passkeys' => env('WIRE_USERS_PASSKEYS', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Teams
    |--------------------------------------------------------------------------
    |
    | Roles and permissions scoped per team, which is the permission layer's own
    | teams feature rather than a second one written here — the same
    | `nyoncode/laravel-permission-extended` the roles above need, over the
    | Spatie registrar it inherits. `auto` follows `permission.teams`, so turning
    | it on there is what turns it on.
    |
    | What this module adds is the part Spatie has no opinion about: which team
    | the person is looking at right now, a switcher in the chrome to change it,
    | and the team column on the role screens.
    |
    */
    'teams' => [
        'enabled' => env('WIRE_USERS_TEAMS', 'auto'),

        // The application's own team model and the relation on the user that
        // reaches it. Nothing here ships a teams table: an application that has
        // teams already has both, and one that does not is not using this.
        'model' => env('WIRE_USERS_TEAM_MODEL', 'App\\Models\\Team'),
        'relation' => 'teams',

        // What a team is called in a switcher, and the session key the current
        // one is remembered under.
        'label_attribute' => 'name',
        'session_key' => 'wire.team',

        // The role of a team's manager: a global role, given inside a team, that
        // carries the abilities of the user and role screens — so its holder
        // manages the members and roles of that team and no other. Made with
        // those abilities the first time it is given; null for no such role.
        'admin_role' => env('WIRE_USERS_TEAM_ADMIN_ROLE', 'team-admin'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    |
    | The menu group the module's entries sit under, and where it sorts. An
    | application that wants them somewhere else redeclares the group under the
    | same key — the last declaration wins.
    |
    */
    'navigation' => [
        'group' => 'access',
        'label' => null,
        'icon' => 'outline:users',
        'sort' => 90,
    ],

];
