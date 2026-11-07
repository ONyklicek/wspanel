---
name: wire-admin-development
description: Work with the optional admin shell — the layout, the sidebar, the chrome regions packages fill, and the slots an application owns.
---

# wire-admin Development

## When to use this skill

Use when giving the application its admin frame, changing the sidebar or the top bar, adding a row to the
user menu, or deciding where a new screen renders.

## Workflow

1. `php artisan wire-admin:install` — it publishes `App\Providers\WireAdminServiceProvider` (which holds the
   one `livewire.component_layout` line), writes `resources/views/components/layouts/admin.blade.php`,
   registers the provider in `bootstrap/providers.php` and publishes translations. Idempotent, never
   overwriting.
2. Edit the published layout view. That view is the application's; the package owns only the frame inside it.
3. `vendor:publish --tag=wire-admin::views` for anything the slots cannot express.

## Patterns

```blade
{{-- resources/views/components/layouts/admin.blade.php — the app owns this file --}}
<x-wire-admin::layout>
    <x-slot:head>
        <link rel="stylesheet" href="{{ asset('css/theme.css') }}">
    </x-slot:head>

    <x-slot:brand>{{ config('app.name') }}</x-slot:brand>

    {{ $slot }}
</x-wire-admin::layout>
```

```blade
{{-- The sidebar works on its own, inside any frame --}}
<x-wire-admin::sidebar :linked-only="false" :zone="null" :active-key="null" />
```

## Rules

- **Installing is not adopting.** No provider sets `livewire.component_layout`; a page renders inside the
  shell only once the application's own layout view says so. Point that config at the *application's* view,
  never at `wire-admin::layout` directly — the component needs its props.
- **Slots, never configuration.** There is no `Panel` object, no branding/colour/auth config, no URL scheme.
  That is the panel-builder drift this design avoids. Do not add one.
- **The user menu is filled by packages, not by your layout.** `PageChrome::USER_MENU` is a chrome region:
  `wire-module-users` contributes the profile link, `wire-module-auth` the sign-out, each with a `sort`
  because provider order is composer's discovery order. The `user` slot is for what the *application* owns.
  One row is `<x-wire::menu-item>` (wire-core, so a package that does not depend on the shell can draw one).
- **The shell owns a signed-out frame and no authentication.** `<x-wire-admin::auth-layout>` is a head and a
  card; the screens inside it belong to `wire-module-auth`, over Fortify.
- **It reads seams and holds no state**: `Workspace::navigation($zone, $linkedOnly)`, `ResolvesPageUrls`,
  `Zone::current()`, `ActiveNavigation`. Work nothing out in the Blade — `Sidebar::active()` is built once in
  the component and passed to every row, and it is **protected** on purpose: a public method on a Blade
  component reaches the view as an `InvokableComponentVariable` of the same name and shadows the object.
- **Zone and active key are read at page render, in the component constructor**, never re-derived per render.
  Inside a Livewire update `Route::currentRouteName()` is `livewire.update`, so a re-derived answer is right
  once and null for ever after — while rendering perfectly.
- **`aria-current` is `page` for the page, `true` for the branch**, never both on one screen. A row with
  children renders as a disclosure `<button>` and never says `page`; the child that links there does.
- **An unrouted entry keeps its row and loses its link** (`aria-disabled`) — the honest picture of a
  half-routed catalogue. `:linked-only="true"` drops those rows instead.
- **The mobile handle listens to the media query, not to `resize`** — the same query the `lg:` classes match
  on, so the two cannot disagree at the boundary.
- This package ships screens, so `<x-wire::*>` tags are correct here. That is the opposite of the render
  engine's rule, and the split is deliberate: these render once per page, not once per row.
