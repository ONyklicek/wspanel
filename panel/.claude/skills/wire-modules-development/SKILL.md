---
name: wire-modules-development
description: Install, adapt or write a ready-made module — users, auth, settings, notifications, audit, media — and the registries a module fills.
---

# wire-modules Development

## When to use this skill

Use when installing one of the seven shipped areas, changing what one of them contributes, or writing a module
of your own.

## Workflow

1. `describe-module` — it reports which modules are actually registered here, their dependencies, and the
   resources, dashboards and navigation group each declares. No module requires another, so never assume one.
2. `composer require nyoncode/wire-module-<name>`. That is the whole installation: the provider registers the
   module with `PluginManager`, and the registries, the router and the search palette pick it up.
3. To change a screen, declare your own resource on the same key or adjust the module's config — never edit
   the package's classes.

## Patterns

```php
use NyonCode\WireCore\Core\Modules\Module;
use NyonCode\WireCore\Core\Plugin\Contracts\HasDependencies;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationGroup;

// A module is one business area's manifest.
final class OperationsModule extends Module implements HasDependencies
{
    public function getId(): string
    {
        return 'operations';
    }

    // PluginManager::register() refuses a module whose dependency is not
    // registered yet — that is the ordering guarantee, already built.
    public function dependencies(): array
    {
        return ['billing'];
    }

    public function resources(): array
    {
        return [TaskResource::class, DocumentResource::class];
    }

    public function dashboards(): array
    {
        return [OverviewDashboard::class];
    }

    public function navigation(): ?NavigationGroup
    {
        return NavigationGroup::make('operations')
            ->icon('outline:banknotes')
            ->sort(20)
            // A closure, not style: navigation() runs while core spreads modules
            // into the registries — before this package's provider registered its
            // translations, and the translator caches the miss for the request.
            ->label(fn (): string => __('wire-module-ops::messages.operations'));
    }
}
```

```php
// A package ships a module by registering it from its own provider — that is what
// separates a shipped module from one an application declares in config.
$this->app->resolving(PluginManager::class, function (PluginManager $manager): void {
    $manager->register(new OperationsModule);
});
```

## Rules

- **A shipped module is a plugin, not a new kind of thing.** It names resources and dashboards;
  `WireCoreServiceProvider` spreads those into the resource registry, the dashboard registry and the
  navigation groups, and everything downstream reads them through `Foundation\Registration\Catalog`.
- **Every module must stay removable.** `composer remove` takes the area out, and the module's config drops
  or replaces what it contributed while the package stays. Where a module ships a screen over something the
  application already owns, **the application's declaration wins**.
- **Permissions always go through `nyoncode/laravel-permission-extended`**, never bare
  `spatie/laravel-permission` — the wildcard matching, super-admin gate and permission-change events these
  screens assume exist only in the extended package. A model carrying Spatie's `HasRoles` directly is
  deliberately not detected, and the role and team surfaces stay off.
- **Fortify owns the security, `wire-module-auth` owns the screens.** One-time codes are the only
  authentication this stack owns — four switches under `wire-module-auth.codes`, all off by default.
- **An auth screen changes through its seams, never by forking the module or working around it.**
  Fields: `AuthForms::extend(AuthForm::Login, fn (array $fields) => …)` in the application's provider
  (it boots last and wins). The identity field is named after `fortify.username`, so an application
  that signs in by username or staff number extends the screens that post to Fortify's `email` —
  `ForgotPassword`, `ResetPassword`, `Register` — and tells `Fortify::createUsersUsing()` about every
  field it adds. The frame is `wire-module-auth.layout`. A Fortify response is rebound through its
  contract (e.g. `EmailVerificationNotificationSentResponse`; the frame prints `session('status')` as
  text). One screen's markup: `Fortify::loginView(…)` inside `<x-wire-module-auth::screen>`; all of
  them back with `'views' => false`. `search-wire-docs` "Customizing The Screens" has the details —
  read it before calling any of this a package bug.
- **Two-factor and teams are `auto` switches that look for the thing itself** (Fortify, the permission
  package) and stay off when it is absent. Do not force one on with a flag the feature cannot honour.
- **`wire:install` never runs composer.** It offers what it found and prints the `composer require` line for
  what it did not — that second list is the point, not an oversight.
- These packages publish their views for a consumer to edit, so `<x-wire::*>` Blade tags are correct in them.
  The render engine (`wire-core`, `forms`, `table`, `panels`, `sortable`) is the opposite case.
- `packages/module-users` is the reference implementation; read it before writing a module of your own.
