<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- This panel is self-hosted on the server it manages (Debian 13, no Docker in production). It is deployed by `../provision/install.sh`, not Laravel Cloud.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>

<!-- wire-boost:guidelines:start -->
## WireStack

WireStack is a set of Livewire packages for building admin UIs with fluent, Nova/Filament-style APIs:

- **wire-core** — shared foundation: actions, modals, notifications, infolists, widgets, icons, colors.
- **wire-forms** — form fields, validation, save lifecycle.
- **wire-table** — data tables: columns, filters, actions, summaries, sub-rows, exports.
- **wire-sortable** — drag & drop row/column reordering for wire-table.

Dependency graph (each depends only on those to its right):

    wire-sortable -> wire-table -> wire-forms -> wire-core

### Conventions

- **Fluent, declarative APIs.** Build UI by composing objects: `TextColumn::make('name')->sortable()->searchable()`.
  Prefer this over ad-hoc Blade.
- **Canonical ownership.** Shared behaviour lives once in `wire-core` Foundation concerns
  (`HasColor`, `HasIcon`, `HasSize`, `HasVisibility`, `HasName`, `HasLabel`). Extend the existing concern
  instead of creating a local variant.
- **Render reusable markup from PHP**, returning `Illuminate\Contracts\Support\Htmlable` via `getXHtml()`
  methods; Blade only consumes the rendered HTML.
- **Components are created with `::make($name)`** and configured by chaining setter methods that return `$this`.
- **Failures throw; they are never returned as an error shape.** Every wire exception is `final`, lives in
  its package's `Exceptions/`, and implements `WireCore\Foundation\Contracts\WireException` — so
  `catch (WireException $e)` catches the whole stack. Each extends the SPL class the failure really is
  (`InvalidArgumentException` = bad argument, `RuntimeException` = bad state), which is also why catching
  the SPL class keeps working. Build them with named constructors
  (`TableHasNoDataSourceException::make()`), not `new`.

### Discover the API with the wire-boost MCP server

- `search-wire-docs` — find the documentation section that answers the question. The full wireStack
  documentation is indexed by section, not summarised.
- `fetch-wire-doc` — read a section in full by the id `search-wire-docs` returned. Do not stop at the snippet.
- `list-component-types` — list available columns, fields, filters, actions, entries, widgets.
- `describe-component-api` — see the fluent methods of a specific type, their defaults, and the values
  each parameter accepts.
- `list-wire-components`, `describe-table`, `describe-form`, `describe-infolist` — inspect existing components.
- `list-icons` — valid icon names for `->icon()`.
- `validate-wire-component` — **run this after writing or editing a component.** An unknown color renders
  gray, an unregistered icon renders nothing, and a name the model cannot resolve renders an empty cell.
  None of the three throws, so a passing render test does not rule any of them out.

### Versions and the 1.x → 2.0 upgrade

The current line is **2.0**: it needs **Livewire 4**, PHP 8.2+, Laravel 12.61+ / 13.12+. The 1.x line stays
on Livewire 3 and no release runs on both, so Livewire is upgraded first.

When moving an application across, read the guide before changing any constraint — `fetch-wire-doc` with
`docs/start/upgrade.md`, and `search-wire-docs` for any symbol that stopped resolving. What breaks loudest:

- The nine `NyonCode\WireCore\Concerns\*` trait shims are gone — import from `Actions\Concerns\*`
  (`Foundation\Concerns\HasColor` for colors). So is `WireTable\Concerns\TableQueryService` (now `Services\`).
- `Widget::lazy()` / `isLazy()` are gone and never deferred anything; defer the component instead.
- The registration and routing contracts were renamed with no aliases: `NavigationSource` →
  `Foundation\Registration\Contracts\RegistrySource`, and `ProvidesResourcePages` /
  `ConfiguresResourceRoutes` / `RoutePage` → `WireCore\Foundation\Routing\…`.

And what breaks silently: table and field views published from 1.x keep working while missing the new row
markup, the gesture markup and the `Alpine.data()` field controllers — re-publish them
(`--tag=wire-table::views --force`) and add `@wireStackScripts` to the layout `<head>`.

## wire-admin

The **optional** admin shell: a layout and a sidebar over what is already registered. It requires
`wire-panels` and **nothing requires it** — a composer boundary is the opt-in, so an application that wants
resource pages and its own chrome simply does not install this.

- **Installing is not adopting.** No provider sets `livewire.component_layout`. A page renders inside the
  shell only once the application's own layout view says so, and the sidebar works alone inside any frame.
- **`php artisan wire-admin:install` is how an application asks.** It publishes `App\Providers\WireAdminServiceProvider`
  (which holds that one config line), writes `resources/views/components/layouts/admin.blade.php`, registers the
  provider in `bootstrap/providers.php` and publishes translations. It also edits `resources/css/app.css`: the
  `@source "../../vendor/nyoncode"` line, `@plugin "@tailwindcss/forms"` (the field views take their border and
  padding from it) and `@custom-variant dark (&:where(.dark, .dark *))` (the theme switch sets the `dark` class),
  and adds `@tailwindcss/forms` to `package.json`. Idempotent and never overwriting; an app with
  no `bootstrap/providers.php` is told the line to add (`AdminInstallException`, caught and warned) and the rest
  still completes. Failures are exceptions, never a status nobody checks.
- **It also writes the dashboard the admin lands on** — `app/Dashboards/OverviewDashboard.php` and
  `app/Livewire/Dashboards/ShowOverview.php`, registered in `config('wire-core.dashboards')`. Both are the
  application's files and counting *its* rows is the point: the shipped one counts users because that is the
  one table every Laravel application has. It claims the panel's own path (`routePrefix()` returning
  `ConfiguresRoutes::ROOT`), so `/admin` is the dashboard rather than a redirect to whichever screen sorts
  first, and its menu entry names no group, which renders it above every group a module declares. Only one
  thing may sit at that path — a landing page of your own replaces it rather than joining it.

- **Two tags, both class-based**: `<x-wire-admin::layout>` (slots: `head`, `brand`, `topbar`, `user`, default)
  and `<x-wire-admin::sidebar :linked-only="false" :zone="…" :active-key="…" />`.


- **The application writes the layout view, the package writes the frame.** A layout that names
  `<x-wire-admin::layout>` and fills slots is what a consuming app owns; `config('livewire.component_layout')`
  points at *that* view, never at `wire-admin::layout` directly (the component needs its props).


- **The user menu is filled by packages, not by your layout.** `PageChrome::USER_MENU` is the third chrome
  region: `wire-module-users` contributes the profile link, `wire-module-auth` the sign-out, each with a
  `sort` because provider order is composer's discovery order. Never hand-write either into the `user-menu`
  slot — that slot is for what the *application* owns. One row is `<x-wire::menu-item>` (wire-core, so a
  package that does not depend on the shell can still draw one); `<x-wire-admin::menu-item>` delegates to it.
- **The shell owns a signed-out frame and no authentication.** `<x-wire-admin::auth-layout>` is a head and a
  card with no navigation; the screens that render in it are `nyoncode/wire-module-auth`'s, over Fortify.

- **Slots, never configuration.** There is no `Panel` object, no branding/colour/auth config and no URL scheme
  — that is the panel-builder drift this design avoids. `vendor:publish --tag=wire-admin::views` is how markup
  changes.
- **It reads seams, holds no state**: `Workspace::navigation($zone, $linkedOnly)` for the menu,
  `ResolvesPageUrls` for every link (null until a package owns routing), `Zone::current()` /
  `Zone::currentKey()` for the zone and the active entry, and `ActiveNavigation` for what is
  marked — built once in the component (`Sidebar::active()`, **protected**: a public method on a
  Blade component is handed to its view as an `InvokableComponentVariable` under the same name and
  would shadow the object) and passed to every row, never worked out in the Blade.
- **`aria-current` is `page` for the page, `true` for the branch.** A resource row stays active on
  that resource's edit screen without claiming to *be* it — the record's tabs above the form say
  that. And a row with children renders as a disclosure `<button>`, which never says `page`: the
  child that links there does.
- **Zone and active key are read at page render, in the component constructor** — never re-derived per render.
  Inside a Livewire update `Route::currentRouteName()` is `livewire.update`, so a re-derived answer is right
  once and null forever after, while rendering perfectly.
- **The menu filter is `wire-admin.navigation.filter`** (`auto` from `filter_threshold` = 12 rows, `always`,
  `never`), driven by `wireNavFilter` in the package's own bundle (`dist/wire-admin-navigation.js`, delivered
  by `<script src="http://localhost:8000/vendor/wire-admin/wire-admin-navigation.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>
<script src="http://localhost:8000/vendor/wire-core/wire-core-dropdown.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>
<script src="http://localhost:8000/vendor/wire-core/wire-core-copy.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>
<script src="http://localhost:8000/vendor/wire-core/wire-core-chart.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>
<script src="http://localhost:8000/vendor/wire-core/wire-core-notifications.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>
<script src="http://localhost:8000/vendor/wire-forms/wire-forms-image.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>
<script src="http://localhost:8000/vendor/wire-forms/wire-forms-fields.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>
<script src="http://localhost:8000/vendor/wire-sortable/wire-sortable.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>
<script src="http://localhost:8000/vendor/wire-table/wire-table-records.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>
<script src="http://localhost:8000/vendor/wire-table/wire-table-selection.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>
<script src="http://localhost:8000/vendor/wire-table/wire-table-live.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>
<script src="http://localhost:8000/vendor/wire-table/wire-table-fill.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>
<script src="http://localhost:8000/vendor/wire-table/wire-table-viewport.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>
<script src="http://localhost:8000/vendor/wire-table/wire-table-sticky.js?id=1794066161" data-navigate-track="reload" data-navigate-once></script>`). Rows carry `data-nav-row` / `data-nav-child` / `data-nav-label` (lower-cased) and
  groups `data-nav-group`; `$store.wireAdmin.filtering` opens folded groups and submenus while a term is typed.
  It is not a second search — ⌘K is. Never add another filter or a second copy of the menu for it.

- **The menu can be a bar: `wire-admin.layout.navigation` = `'top'`** (or `<x-wire-admin::layout navigation="top">`;
  anything else throws `NavigationShapeException`). `<x-wire-admin::top-nav>` reads the same `Workspace` and
  `ActiveNavigation`; a group — and a lone entry with children — is a `wireDropdown` panel, a group of one is a
  link, and what does not fit goes under *More* (`wireTopNav`, hidden in the bar exactly when shown in the
  panel). Below `lg` the drawer is still the menu (`<x-wire-admin::sidebar :drawer-only="true">`), so the bar's
  names are `admin-topnav-*`, never `admin-nav-*`. No rail toggle or ⌘B in that shape.

- **Pins and recent pages are `NavigationMemory`** (wire-core, over the `PreferenceDriver` — `navigation[:zone]`
  bag of keys), drawn by the one Livewire piece of the menu, `wire-admin.nav-pins`. Offered only when
  `stores()` (never over the `null` driver); keys are intersected with the current `Workspace::items()`, never
  drawn from storage; recent is written on `page.mounting` only, never while the menu renders. A row's pin
  dispatches `wire-admin-pin`; the section answers `wire-admin-pinned`. Never give pins a store of their own.
- **Focus is the shell's**: the phone drawer is `x-trap`ped while open (first row focused, focus returned to
  the opener), and `<main>` has `tabindex="-1"` for the skip link. A custom drawer must keep both.
- **An unrouted entry keeps its row and loses its link** (`aria-disabled`), which is the honest picture of a
  half-routed catalogue; `:linked-only="true"` drops those rows instead.
- **The mobile handle listens to the media query, not to `resize`** — the same query the `lg:` classes are
  matched on, so the two cannot disagree at the boundary. A driver caught the resize version failing.

## wire-core

Shared foundation for wireStack. Key building blocks:

### Actions

Row, header and bulk actions are objects with a fluent API and lifecycle hooks:

    Action::make('approve')
        ->label('Approve')
        ->icon('check')
        ->color('success')
        ->requiresConfirmation()
        ->action(fn ($record) => $record->approve());

- Presets: `DeleteAction`, `EditAction`, `ViewAction`, `RestoreAction`, `ForceDeleteAction`, plus bulk presets (`DeleteBulkAction`, `RestoreBulkAction`, `ForceDeleteBulkAction`). They confirm and label themselves; the host supplies `->action()`.
- A chain repeated on a second surface becomes a preset class: `php artisan make:wire-action Archive [--bulk]` writes `app/Wire/Actions/ArchiveAction.php` (or `ArchiveBulkAction`) — defaults set in the constructor, `make(string $name = 'archive')`. Not `app/Actions` (business actions live there). Stubs: `wire-core::stubs`.
- `->url()` takes a **static string or a per-record Closure**, and the two are not interchangeable on a
  record-less surface (a header action, the table's empty state): a string resolves with or without a
  record, a Closure needs one and stays unresolved — the action then renders as a plain button, not a
  link. `Action::render()` / `getUrl()` therefore take an optional record, matching `RendersAsButton`.
- **`wire:navigate` is one switch, `config('wire-core.navigate')` (default `true`), owned by
  `Foundation\Routing\ClientNavigation`.** Never hardcode `wire:navigate` on a link in a view: write
  `@wireNavigate($url)` (second argument = the link's own `?bool`), and redirect with
  `$this->redirect($url, navigate: app(ClientNavigation::class)->shouldNavigate($url))`. Only a page of this
  application (same scheme/host/port, or a path) is ever navigated; a new tab never is. Per link:
  `->navigate(false)` on `Action`, `HeaderAction` and any table column; `Table::recordUrl($url, navigate: false)`;
  `<x-wire::menu-item :navigate="false">`.
- Actions can open modals via `->modal(...)` and multi-step wizards via `->steps([...])`.
- A wizard step's `->schema(fn (array $data) => [...])` Closure builds its fields from data entered in
  earlier steps; the bag is live even for header actions (no record), so later steps can branch on it.
- An action with a form (`->form([...])`) self-seeds each field from its `->default()` (or a
  type-correct blank), then layers record/context prefill on top with `->fillFormUsing(fn ($record) => [...])`
  (`$record` is `null` for header actions), which overrides the seed. Inside the form, reactive fields use `$get`/`$set` and
  `->afterStateUpdated()` against the live per-frame form-data bag (`mountedActions.{depth}.data`) — see the wire-forms guideline.
- Add extra footer buttons with `->modalFooterActions([ModalFooterAction::make('preview')->action(fn ($data, $set) => …)])`.
  The callback gets the live form `$data` and a `$set` writer; `->submitsForm()` validates first,
  `->closesModal()` closes after, `->position('before'|'after')` places it around Cancel/Submit.
- Modals **stack as a live frame array** (not one active + frozen parents): opening an action while a
  modal is open (from any callback with the host `$component` — a footer/field/infolist action calling
  `$component->mountAction(...)` / `$component->openActionModal(...)`) layers the new modal on top while
  **every parent stays a live, reactive form** behind it (dimmed + click-inert; only the top is interactive).
  A nested callback can write back into an ancestor via `$setParent(path, value)` / `$setFrame(depth, path, value)`,
  read `$parentData`, and receive `$arguments` (passed to `mountAction($name, [...])`) — so a sub-form can
  create-and-select into the form that opened it. Declare a nested action inline next to its opener with
  `->registerActions([Action::make('createCustomer')->…])` (resolved by name; no top-level registration needed).
  Closing the top (Escape/close/backdrop) pops just that level and resumes the parent with its data intact.
  Deep nesting works (capped at `Modals\ModalStack::MAX_DEPTH` as a runaway guard); only the top takes focus/Escape.
  `->requiresConfirmation()` asks before running (native `wire:confirm`, translated default message);
  `->confirm('Really reset?')` sets a custom message.
- Color, icon and visibility come from the shared `HasColor`, `HasIcons`, `HasVisibility` concerns.
- On actions, `->label()`, `->icon()`, `->color()`, `->tooltip()` and `->size()` each accept `string|Closure|null`,
  so they can be computed per row — the Closure receives the record: `->color(fn ($record) => $record->isPaid() ? 'success' : 'danger')`.
  This differs from table columns, where `->color()` is static and per-state colors use `->colorUsing()` / `->colors()`.
- `->action(fn ($record) => …)` runs the action; the callback receives the current record.
- Actions are not table-only. Any Livewire component can declare and run them (modal, form, wizard,
  confirmation, full lifecycle) with the `WithActions` trait plus a `<x-wire-actions::modal-host />` component —
  see the wire-forms guideline. The engine is canonically owned by
  `Actions\Concerns\InteractsWithActions` (form-agnostic, here in wire-core) and the wire-forms
  form bridge; `WithTable` composes the same engine. Extend it rather than reimplementing action
  handling on a component.
- **A halt needs none of that.** `Actions\Concerns\InteractsWithHalt` is "stop, ask, continue" on its own:
  `$this->halt($halt, then: 'method', arguments: [...])` from any method, plus
  `<x-wire-actions::halt-host />` (a host rendering the modal host already draws it).
  The resume is a **method name and scalars**, never a closure — a halt is answered on a later request than
  the one that raised it. Without wire-forms a halt has no fields; its declared rules are still checked.

### Modals

`Modal`, `ConfirmationDialog`, `SlideOver` and `Wizard` (modal **config** objects). Prefer attaching a
modal to an action over building bespoke modal state. Mobile presentation is per action and independent
of the modal type — it applies to a form, an infolist **and** a confirmation modal alike:
`->slideOverOnMobile()` renders it as a bottom-sheet that slides up from the bottom edge,
`->fullScreenOnMobile()` fills the viewport; both keep the centered dialog on desktop and scroll the body
inside the panel. These do **not** imply `->slideOver()` — that is a separate desktop right-hand panel;
combine `->slideOver()->slideOverOnMobile()` for a desktop slide-over that becomes a mobile bottom-sheet.

For a **standalone** modal in your own view use the component tag —
`<x-wire-modals::modal wire:model="show" heading="…">…<x-slot:footer>…</x-slot:footer></x-wire-modals::modal>`
(also `::confirmation`, `::slide-over`). For **PHP-first** rendering without a `<x-*>` tag, echo the
Htmlable render object: `{{ new NyonCode\WireCore\Modals\Html\Modal(heading: '…', wireModel: 'show', body: $form) }}`.
Both render the same shell. (Three families under `Modals\`: `Html\*` = Htmlable render objects,
`Modals\Modal` etc. = config, `Modals\View\*Component` = the Blade components — don't conflate.)

Either form works inside your own `@island`. Livewire renders a targeted island without the shared
`$__livewire` a full render has, and these modals build their view from an explicit data array, so
`@entangle` and `@this` would otherwise fail with `Undefined variable $__livewire` — wire-core
restores that scope for the duration of an island render, so you do not have to. This applies to any
view you render from PHP inside an island, not only these.

### Mobile sheets

Floating panels (dropdowns, action-group menus, select/date/tag pickers, table filter & column-toggle
panels) and the modal variants above present as a **bottom sheet** below a breakpoint. Global defaults
live in `config('wire-core.mobile')`: `sheet` (bool, default `true`) and `breakpoint` (`sm`|`md`|`lg`,
default `sm`). Override per component with `->sheetOnMobile(true|false)` and `->mobileBreakpoint('md')`
(actions/fields/filters/`Table`/`ActionGroup`); the `<x-wire::dropdown>` tag takes
`:sheet-on-mobile` / `:breakpoint`. Searchable selects default to floating. Sheets add safe-area padding,
a drag-to-dismiss grabber and a focus trap automatically — do not re-implement these.

**Native control on a phone.** Every surface with a browser counterpart (`Select`, `BelongsToSelect`,
`DateTimePicker`, `TimePicker`, `SelectFilter`, `TernaryFilter`) uses `Foundation\Concerns\HasNativeControl`:
`->native()` is the browser's element everywhere, `->nativeOnMobile()` only below the same mobile breakpoint
(global default `wire-core.mobile.native`, default `false`). Views branch on the one resolved value,
`getNativeControlMode()` (`NativeControlMode::Never|Always|Mobile`); Mobile renders both controls and
`MobileSheet::showBelow()` / `hideBelow()` pick one in CSS, with no sheet for that field. A surface whose
custom control carries something the native one cannot overrides `supportsNativeOnMobile()` (only remote
search and create/edit option stay custom). A clock step goes native as a `<select>` of the slots — beside a
native date on a datetime, joined by `wireNativeDateTime` — never as `<input type="time" step>`, which iOS ignores.
`DateTimePicker` also validates its bounds and its daily `minTime()`/`maxTime()` window server-side (`DateWithinBounds`) — a native wheel ignores them.
**Touch-built control on a phone:** `Foundation\Concerns\HasNativeControl` — `->touchOnMobile()`, global
`wire-core.mobile.touch` — gives a picker a scroll-snap wheel and a select a bottom-sheet list, keeping remote
search, create-option and disabled days. Precedence is one rule: `native()` > `touchOnMobile()` >
`nativeOnMobile()`. The select list is `wire-core::partials.select-touch-sheet`, rendered by `select-control`
beside the floating panel on the same `wireSearchableSelect` state — never a second select component.
The phone control's id is `mobileControlIdFor($id)` (`{id}-native`, a picker's wheel `{id}-wheel`, null when
the phone sees the desktop trigger); a label goes through `MobileSheet::labelTargets()`, which points it
at that control below the breakpoint — never spell a suffix by hand. Every select goes through
`wire-core::partials.select-control` — never hand-write a `<select>` or include the combobox beside it.

### Layouts

Canonical layout vocabulary shared by forms and infolists (`NyonCode\WireCore\Foundation\Schema\*`):
`Grid`, `Section`, `Fieldset`, `Flex` (side-by-side flexbox row, stacks below `->from('md')`, with
`->justify()/->align()/->gap()/->wrap()/->grow()`), `Tabs`+`Tab`, `Wizard`+`Step`, `Callout`
(`->heading()->color()/info()/success()/warning()/danger()->icon()->dismissible()`) and `EmptyState`
(`->icon()->heading()->description()->actions([])`). Column counts accept an int or a Filament-style
per-breakpoint map: `->columns(['default' => 1, 'md' => 2, 'lg' => 3])`. Prefer these over ad-hoc Blade
grids; the forms `Alert` field is the field-style alias of `Callout`.


Standalone Blade tags mirror them for plain views: `<x-wire::grid>`, `<x-wire::flex>`, `<x-wire::section>`,
`<x-wire::fieldset>`, `<x-wire::callout>`, `<x-wire::empty-state>`, and the Alpine-driven `<x-wire::tabs>` /
`<x-wire::wizard>` (with nested `<x-wire::tab>` / `<x-wire::step>`).

The standalone tabs/wizard are client-side only (no per-step validation) — use action-modal wizards or
form schema for validated flows.

### Workflow / state machine

`WorkflowState::for(StatusEnum::class)->column('status')->allow($from, $to)->guard($to, fn)->after($to, fn)`.
A **seam, not an engine**: it owns states, edges, guards and side effects, and delegates every
meaning — no process definitions, no approval modelling, no scheduler. Transitions save through the ordinary
path, so tenant scoping and audit come along without rewiring.

**Never add colour/label/icon here.** The status is an enum implementing `Enum\HasColor`/`HasLabel`/`HasIcon`
and `BadgeColumn` already renders it — a second map is a parallel vocabulary that drifts. (This is also why
`StatusColumn` was never built: `BadgeColumn` covers it.)

**Two refusals, deliberately different.** An illegal edge **throws** — silence leaves a record where the user
believes it moved on. A guard veto returns **false** — "not yet" is a domain answer, not a broken machine.
Guards on one state must *all* pass (separate rules; `&&`-ing them loses which said no). `after()` runs after
persistence, so a hook cannot fire for a save that rolls back. `allow()` takes a list of origins because
"anything up to here may be cancelled" is the common shape.

`TransitionAction::to($state)->workflow($machine)` is an ordinary action that does two more things by itself,
so **no surface needs to know a workflow exists**: it hides where the move would not work (`isHidden($record)`,
which every action surface already asks, and `canExecute($record)` before running), and pressing it performs
the transition (attaching a machine sets the action callback; an explicit `->action()` still wins, either
order). `isAvailableFor($record, $user)` answers the machine's half alone. An action for a transition the user
cannot complete exists to be refused, and an unpredictable refusal reads as an application bug. Label / colour
/ icon come from the target enum via the same resolution BadgeColumn uses, so button and badge cannot
disagree; `->visible()` and the machine's answer stay separate so neither overrules the other, and a
record-less check leaves the machine out of it.

## Domain modules

A **domain module** is the second axis — `billing` beside `operations` — and it is a **plugin**, not a parallel
registration system: it registers from `config('wire-core.plugins')` when an application declares it, or from a
package provider's `$this->app->resolving(PluginManager::class, …)` when a package ships one; `PluginManager` gives it one id, the
register-then-boot lifecycle and `dependencies()` checking, and **there is deliberately no ModuleRegistry**
(that list already exists). `DomainModule` only *declares* — `resources()`, `dashboards()`,
`navigation(): ?NavigationGroup` — and `WireCoreServiceProvider::bootModules()` spreads those into the three
registries. **Never make a module reach for `DashboardRegistry` itself**: a dashboard is L2 and the module
contract is L1, so naming classes is what keeps the layer test green. Not a module's job: workflows (the
resource carries one), policies (Laravel's Gate), workspaces (a service over the registries). `describe-module`
reports what each declares. **A package adds, it never overwrites**: two classes on one key are refused, so an
application adjusts a shipped module through a **`table.composing`** / **`form.configuring`** /
**`infolist.configuring`** hook scoped with `for: '<key>'`, never by subclassing its resource (a subclass keeps
the parent's key).

### Plugins and hooks

A plugin is `getId()` + `register(PluginManager)` + `boot(PluginManager)`, registered from
`config('wire-core.plugins')` or from a package provider's **register** phase. `register()` must not resolve
services (they may not be bound yet); `boot()` runs after every plugin is registered — and **closes
registration**: `PluginManager::register()` after `boot()` throws `PluginRegistrationException`, because a
plugin arriving then is never booted and a module's declarations never reach the registries. Never register a
plugin in a provider's `boot()`, and never defer a provider that registers one. A config entry that cannot be a
plugin is refused too (blank strings excepted), rather than skipped. `HasDependencies::dependencies()` lists ids that must already be registered —
checked at registration, so **list a dependency before its dependant in config** or registration throws.
`HasConfiguration` merges `defaultConfig()` under `wire-core.plugins.config.{id}`.

**A hook does nothing until something runs it.** `hook('name', $cb)` stores a callback; behaviour changes only
where a `runHook()` / `runTypedHook()` call exists. Callbacks run by ascending priority; an array-hook callback
returning an array replaces the payload, anything else leaves it unchanged; a typed-hook callback replaces it
only with an instance of the payload's own class (mutate and return it), anything else is ignored. Names come from the `Hook` enum
(strings still accepted). **`table.composing` ≠ `table.configuring`**: composing runs once on the table instance
the host built (a column added there renders), configuring runs in `TableQueryService` on arrays the planner
consumes (a column added there is searched and sorted on and never drawn). `form.configuring` is the form's
composing hook, `infolist.configuring` the detail page's. Eight hooks are **typed-only** — those three plus
`widget.configuring` (before widget keys are stamped and before visibility filters), `export.configuring` (in
`buildTableExport()`, so a download and a queued file are one export), `navigation.building` (the flat entry
list, before grouping), `page.mounting` (last in a resource page's mount, so the record is resolved) and
`search.querying` (per resource, before `get()` and before the policy check). No array counterpart, and new
hooks stay that way. `hook(..., for: 'invoices' | Model::class | Page::class)` scopes a callback to one
component through the payload's `HookTarget`; a scoped callback is skipped when a dispatch carries no target.
Two hooks belong to no component and name something else: `navigation.building` takes a **zone**,
`search.querying` the searched resource's catalogue key. **Never write the container/`hasHook()` guard by
hand** — `HookDispatch::typed($hook, fn () => new …Payload(…))` owns it, and its `null` means "nobody
listened", never "nothing changed" (folding the two with `??` undoes a callback that emptied the array).

**The rule that cost this repo a defect: the first parameter's type hint decides which dispatcher a callback
belongs to.** Every built-in lifecycle point (`table.configuring|querying|queried`, `form.saving|saved`,
`action.executing|executed`) runs **both** dispatchers back to back, so each callback must belong to exactly
one:

| First parameter | Dispatcher | Receives |
|---|---|---|
| `array` | `runHook()` | the payload array |
| a DTO (`TableQueryingPayload`, `FormSavingPayload`, …) | `runTypedHook()` | the object |
| **no type hint, or no parameter** | `runHook()` | the array — **never the DTO** |

**Always type-hint the first parameter.** An unhinted `function ($payload)` used to answer "no" to both
questions, so *both* dispatchers ran it: every side effect happened twice, and the second pass handed it a DTO
where `$payload['data']` fatals — after the callback had already done its work. Unhinted now falls to the array
side deliberately, because that is what a callback written before DTOs existed expects.

**Do not reach for a hook to enforce something.** A hook covers one path and only while a PluginManager is
bound. Security-shaped behaviour belongs lower: tenancy is a global scope, not a `table.querying` hook — see
below.

### Multi-tenancy

Off by default (`wire-core.tenancy.enabled`), **strict once on**. The shipped resolver answers the tenant held by
`Core\Tenancy\CurrentTenant` (scoped: every request and job starts empty), null when nothing was entered; enter
one with `app(Tenancy::class)->runAs($tenant, fn () => …)` in a job, command, seeder or test — it restores what was
current, even on a throw. **Never keep "the current tenant" anywhere else**. Isolation is
`wire-core.tenancy.isolation`: `column` (default), `database` (a database per tenant: models use
`BelongsToTenantDatabase` on the `tenancy.database.connection`, `wire:tenants:create` / `wire:tenants:migrate`,
no tenant entered = the first query throws) or a class implementing `IsolatesTenants` (`enter`/`leave`, called only
by `CurrentTenant`). Binding a `TenantResolver` of your own replaces the default. **Queued jobs carry the tenant** (payload
`wireTenant`, re-entered on `JobProcessing`, the previous tenant restored after — so `sync` jobs do not strip the
request's); a deleted tenant fails the job. `runAs()` dispatches a returned `PendingDispatch` inside the tenant (and
returns null), so `fn () => Job::dispatch()` is safe. Mark models with `BelongsToTenant` — opt-in per model, because the framework cannot know which
tables are tenant-owned and guessing would be a guess about who may see what.

**The fail-safe is the whole story: tenancy on with no tenant resolved returns NOTHING, never everything.**
Every ordinary state produces a null tenant (before login, a worker, a console command), so reading null as "no
constraint" hands every row to every one of them. The scope emits `0 = 1`. It is deliberately **not**
`where tenant_id is null` either — an unowned row would then be visible to everybody.

**A non-Eloquent source builds no query, so no scope reaches it** — wrap it: `new TenantScopedDataSource($source, app(Tenancy::class))`. It constrains the **plan**, not the returned rows, which is what makes it safe on `count()` and `paginate()` too (those answer without handing rows over) and lets a source that cannot honour the filter refuse out loud. `resolveRecord()` takes a key rather than a plan, so there the record is fetched and checked — without that a tenant reaches another tenant's row by typing its id into a URL. Same fail-safe, and tenancy off delegates untouched.

**A global scope, not a plugin hook.** A hook covers one read path and only while a PluginManager is bound; a
global scope covers every query Eloquent builds — a listing, a relation, `find()` in the app's own controller,
`update()`, `delete()`, and a queued job resolving by key. `create()` is attributed to the current tenant and
**throws** when none resolves, because a row with a null tenant is invisible to every scoped query afterwards:
the user's work is gone and nothing said so. An explicitly set column is left alone (seeder, deliberate move).
The column is qualified — a scoped model is routinely joined and joined tables carry their own `tenant_id`.

`Model::acrossAllTenants()` steps past it, verbosely and greppably, for admin reports and console commands.
**Not covered:** a non-Eloquent `DataSource` has no Eloquent query to scope — constrain it in the source.

Resolve `Tenancy` per query, never hold it: a global scope is added once per model class per process, so a
captured instance answers with whoever was current the first time that model was touched — wrong on the second
Octane request and every job after the first.

### Queued actions

`->queue()` / `->onQueue('reports')` / `->onConnection('redis')` on any action (naming a queue or connection
implies `->queue()`). **Default stays synchronous and should** — a user clicking Delete expects the row gone on
return; this is for the long tail (bulk over ten thousand rows, an export that would time out).

**The job carries names and keys, never objects**: host class, action name, record keys, form data — all
scalars. Not the action (closures), not models (stale by the time a worker takes them; ten thousand would be a
megabyte of payload). It rebuilds the host, calls `resolveActionByName()`, and reads records fresh via
`resolveRecordsByKey()` — so a row edited between click and run is acted on **as it is at run time**. A single
key still arrives as `$record`, a set as `$records`.

**A queued action has no browser.** `$set` / `$setParent` / `$setFrame` / `$close` / `$replace` / `$halt` are
bound to **throw** `QueuedActionException`, never to no-op — a silent `$close()` looks like it worked and
surfaces weeks later as "the modal never closes". Report back with a notification; that is what the database
driver is for, since the request that queued the job is gone by then. An action renamed or removed between
dispatch and run throws too.

`RunActionJob` reaches Notifications by class name, not import: both are L2 and the module-layer rule forbids L2→L2 — the
same soft seam `HasLifecycle::resolveNotificationManagerClass()` uses.

**Modal defaults.** `wire-core.modals.default_width`, `slide_over_width`, `close_on_click_away` and `close_on_escape`
are the default of every modal surface — an action's own modal and `->slideOver()`, `Modal`/`SlideOver`/`Wizard`/
`ConfirmationDialog`, `ActionHalt` and the `<x-wire-modals::*>` components — read through
`Foundation\Support\ModalDefaults`. An explicit fluent call or attribute always wins.

### Notifications

`Notification` is an immutable value object dispatched through a driver (current-component, session, livewire,
flasher, **database**, null), selected by `wire-core.notifications.default` — which takes a **list** as well as
a string, so `['session', 'database']` shows the toast *and* keeps it in the bell (a `StackDriver` fans out;
one driver throwing does not silence the rest, and the failure is re-thrown after all have had their turn). From the
environment the list is a comma-separated string (`WIRE_NOTIFICATIONS_DRIVER=session,database`); never test it with
`(array) config(...)` — ask `Notifications\Support\ConfiguredDrivers::names()` / `::includes('database')`. Unless
`NotificationManager::setDefaultDriver()` sets one in code, the default is the configured driver wrapped in
`CurrentComponentDriver`: it resolves the active Livewire component via `Livewire::current()` itself, so
`NotificationManager::send($notification)` and the `InteractsWithNotifications`/`sendNotification` helpers no
longer thread `$this`. A custom per-component driver that needs the component must wrap itself in
`CurrentComponentDriver`.

**Persistent notifications.** The transient drivers deliver to the page being rendered — useless for a queued
export finishing twenty minutes later, when there is no component to dispatch to. `DatabaseDriver` writes the
row instead; `NotificationCenter` reads it and `@livewire('wire-notification-bell')` renders it. Every read is
scoped to the recipient resolved by `ResolvesNotifiable` (default: the authenticated user), **`markAsRead($id)`
included** — the id comes from a Livewire action, so an unscoped lookup would let one user mark another's
notification read. With no recipient the driver writes **nothing** rather than storing an unreachable row,
which is the ordinary state on a queue worker; bind your own resolver when a job must address someone. The
table matches Laravel's `notifications` shape so an app can share its own, and the id is a **ULID** in that
uuid column: a bulk job puts five rows in one second, where `created_at` alone orders them arbitrarily.

Fluent `Notification`: `->title()`, `->duration(ms)`, `->icon()`, `->position()`, `->persistent()` (sticky,
duration 0, no countdown bar), and `->action('Undo', 'event')` / `->action(NotificationAction::make(...))` —
action buttons dispatch a Livewire event on click (host listens with `#[On('event')]`). `NotificationAction`
supports `->payload([...])`, `->color()`, `->keepOpen()`. The built-in drivers forward the full payload, so
titles/actions/persistence survive the server round-trip.

Toast container: `<x-wire-notifications::toast-container />` — props `position`, `duration`, `event-name`,
`session-key`, `stack` (collapse into a pile that fans out on hover), `progress` (per-toast countdown bar, hover
pauses it and the auto-dismiss), `max` (cap visible toasts, overflow into a "+N more" pill). Honors
`prefers-reduced-motion` and exposes an `aria-live` region. It renders the flashed notification too, which is how
a toast raised by a request that then redirected — `successRedirect()`, a create page landing on its new record —
is shown at all: the event died with the document, the flash crossed.

### Infolists

Read-only counterpart of forms. `Infolist::make()->schema([...])` with entries: `TextEntry`, `BadgeEntry`,
`IconEntry`, `BooleanEntry`, `ListEntry`, `ImageEntry`, `ColorEntry`, `KeyValueEntry`, `RepeatableEntry`.
Layouts: the shared vocabulary above (`Section`, `Grid`, `Fieldset`, `Flex`, `Tabs`, `Wizard`, `Callout`,
`EmptyState`) — see the Layouts section. Integrates with `ViewAction->infolist()`. A custom entry starts from
`php artisan make:wire-entry Money` (`app/Infolists/Components/MoneyEntry.php` + `resources/views/infolists/entries/money.blade.php`,
the view gets `$field` and reads `getFormattedState()`).

Actions: `Section::headerActions([...])`, `Entry::actions([...])`, and `RepeatableEntry::actions([...])`
(per-row, gets the row `$record`) — dispatch via the host's `callInfolistAction()` (works in an action modal /
`WithActions` host); names must be unique per infolist. `RepeatableEntry::with([...])` eager-loads relations on
the rows to avoid N+1 when child entries read nested relation paths.

### Editable panels

An infolist you can edit: same declarative schema, but editable entries write **straight back to the
record**, one commit per change — no Save button, no form buffer. `Panel::make()->record($model)->schema([...])`
(namespace `Panels\`, `PanelComponent` base or compose `Panels\Concerns\WithEditablePanel` into an existing
component and implement `panel()`). The view must `@include('wire-core::partials.floating-assets')` to load
the shared `wireEditableCell` engine.

Editable entries (`Panels\Components\`): `ToggleEntry`, `CheckboxEntry`, `SelectEntry`, `TextInputEntry`.
They extend the infolist `Entry`, so read-only entries (`TextEntry`, `BadgeEntry`, …) mix freely into the
same schema. Same write path — optimistic UI + optimistic locking — as editable table columns
(`ToggleColumn`/`SelectColumn`/`TextInputColumn`); do not invent a second one.

`->rules([...])` validates server-side before the write. `->disabled()` (closure gets the `$record`) and
`->permission('ability')` reject the write server-side, not just cosmetically. Being declared an editable
entry in the schema **is** the write whitelist: a read-only entry name, or any attribute not in the schema,
is refused by the host. Override persistence with `->saveUsing()`, run side effects with `->afterStateUpdated()`.

Choose: infolist = read-only by contract; panel = read *and* edit one record in place; form = buffered
multi-field edit with one Save.

### Widgets

`StatsOverviewWidget` / `Stat`, `ChartWidget` (+ `LineChartWidget`/`PieChartWidget`/`DoughnutChartWidget`
presets and `->options([...])` Chart.js overrides), `BarChartWidget` (pure-CSS bars: `->type('vertical'|'horizontal')`, `->variant('finance'|'system')`, `->showGrid()`, `->verticalLabels()` to rotate each bar's label beside it for long names), `CustomWidget` — and
`TableWidget`, which is **`NyonCode\WireTable\Widgets\TableWidget`, not wire-core's**: it draws rows of a
table inside a card and the engine that draws them is wire-table's, so the class went with it in 2.0. A
`use NyonCode\WireCore\Widgets\TableWidget;` is the class that was removed.

**Never write the host's widget state from a view or from JS.** `widgetLayoutDraft`, `editingWidgets`,
`widgetFilters`, `loadedWidgets` and `dashboardFilters` are `#[Locked]`: every change has a method (`moveWidget`, `placeWidget`,
`resizeWidget`, `removeWidget`, `start/save/cancel/resetWidgetLayout`, `filterWidget`, `loadWidget`,
`setDashboardFilter`, `resetDashboardFilters`), and
those are where a key is checked against the declaration and a size against the grid. A `wire:model` on any
of them throws at runtime.

**More than one arrangement** is `savedLayouts(): true` beside it — a separate opt-in, because a switcher
is not what a dashboard with one layout wants. It rides the same store under a name; the host gains
`saveWidgetLayoutAs()`, `applyWidgetLayout()`, `deleteWidgetLayout()` and `getWidgetLayoutNames()`, and the
shipped controls gain a "Save as" and a select. Applying a saved layout **copies** it onto the current one
rather than pointing at it, so nothing has to remember which name is in use.

**A dashboard a user may rearrange** says `customisable(): true` on the `Dashboard` (off by default; the
stored layout is per user, under the dashboard's key, in `wire-core.preferences`). Then **every widget on
it needs its own `key()`** — a derived key is a position, and a stored layout addresses widgets by key, so
a missing one is refused rather than rendered. `->group('Money')` is the heading it is offered under in the
tray; `->sizes([[2, 1], [4, 2]])` is the list of sizes it may be given, and it binds: the resize buttons
walk exactly those pairs and the first one is the size it arrives at from the tray.
Key the pairs to name them (`->sizes(['S' => [1, 1], 'M' => [2, 1], 'L' => [4, 1]])`) and the editor offers
`S M L` buttons instead of steppers; `->description()` is shown under the heading in the tray.

**Shape a customisable dashboard on the `Dashboard`, not in its page:** `defaultLayout()` is what somebody
sees before arranging anything (`['kpi' => 'L', 'queue' => [2, 1], 'money']` — may depend on the user; the
rest starts in the tray, Reset comes back here), `autosave()` stores every change at once (the mode then ends
with Done, no Save/Cancel), `maxWidgets()` refuses a widget past the limit. A **filter over the whole
dashboard** is `filters(): [DashboardFilter::make('period')->options([...])->default('month')->buttons()]`,
read in `widgets()` through `$this->filter('period')` — one selection in the address (`?dashboard[period]=…`)
that every widget answers, never a per-widget `filter()` repeated on each. A widget that cannot be narrowed
says `->ignoresDashboardFilters()` and is marked while a filter narrows. A hand-written `WithWidgets` host
answers the same through `defaultWidgetLayout()`, `autosavesWidgetLayout()`, `maxWidgets()`,
`getDashboardFilters()` and `$this->dashboardFilter()`, and includes `wire-core::widgets.partials.widget-filters`.

**A `columnSpan()` is resolved against the grid the component lands in, and capped by it.** Write the
number (`columnSpan(3)`, `columnSpanFull()`) and the breakpoints follow the grid — the layout tells each
child what it is drawn in, and `HasColumnSpan::getColumnSpanClass()` steps the span with the columns. Never
hand-write `col-span-*` on a schema or infolist child, and never assume a span wider than the grid clips:
CSS Grid adds the missing column instead, which re-flows the layout and squeezes every sibling into the
remainder. A grid of your own gets its classes from `ResponsiveGrid::cols()` and hands the same argument to
`ResponsiveGrid::span()`; the two shipped ladders are `fieldColumns()` (fields, two columns from `sm`) and
`cardColumns()` (cards and widgets, two from `md`).

**Never let a widget span more columns than the dashboard declares.** `columns(3)` means three is the
widest a tile can be — a wider one does not clip, CSS Grid adds the missing track and squeezes every other
tile on the page into what is left. The steppers and the server both cap at `columns()` for you
(`WidgetSizeOffer`), so the trap is only in hand-written markup: a widget grid resolves its spans through
`ResponsiveGrid::span($span, $ladder)` against the same ladder `ResponsiveGrid::cols()` built, never
through a bare `col-span-*` or `HasColumnSpan::getColumnSpanClass()`, which answers for a grid that ramps
at `sm` and knows no column count.

### Audit log

Add `HasAuditable` to a model and its created/updated/deleted changes persist as `AuditEntry`
rows automatically — the package registers the event subscriber itself, gated by
`wire-core.audit.enabled`. No manual `Event::subscribe()` needed. Retention: configure
`wire-core.audit.retention_days` and schedule `wire-core:audit-prune` (or run with `--days=N`).
A period under one day is refused — `--days=0` exits non-zero and deletes nothing, and `prune(0)`
throws `InvalidRetentionException` — so never use `0` to mean "prune everything".
Suppress logging in seeders/imports with `AuditLogger::withoutAuditing(fn () => …)`.

**The trail sees model writes and nothing else.** `Order::query()->update()`, `->delete()`,
`->increment()`, `insert()` and `upsert()` fire no model event and leave no entry. When a write
has to be audited, loop the models, or dispatch `RecordUpdated` / `BulkActionExecuted` for it.
Do not wrap a query-builder write in `withoutAuditing()` — it was never going to be recorded.
Soft deletes need nothing extra: `restore()` is an `updated` entry, `forceDelete()` a `deleted` one.

**Credentials never reach the trail.** Passwords, anything ending in `_token` or `_secret`,
two-factor columns, recovery codes and `api_key` are dropped by the logger itself — a floor, not a
default, so emptying `wire-core.audit.exclude_columns` does not bring them back. That list adds
application-specific columns on top and accepts `*` (`'billing_*'`). Do not add the credential names
to it "to be safe"; do add your own (`salary`, `national_id`). The entries are rendered old-value
beside new-value on the audit module's screen, so anything you leave in is on a page.

### Tours

A `Tour` is a guided walkthrough registered from a service provider's `boot()`:
`$this->app->make(Tours::class)->register(Tour::make('id')->steps([TourStep::make('table-search')->text('…')]))`.
It runs by itself on the first full page render of a screen it claims, and the browser does the rest;
finishing and skipping both record it, in one Livewire request.

**A step names an element hook, never a selector.** `TourStep::make()` takes a `data-wire` name
(`admin-sidebar`, `table-search`, `admin-nav-item`) and throws on anything that is not kebab-case.
Find real names with `grep -rho 'data-wire="[a-z-]*"' vendor/nyoncode | sort -u` — do not invent one,
because a well-formed name nothing renders is silently **skipped**, exactly like an element that is
hidden (an unopened dropdown, the bulk bar before a selection). Narrow one element among many with
`->where('resource', 'orders')` (matches `data-resource="orders"`).

**Scope with what already exists.** Who: `->permission('sales.*')`, `->authorize()`,
`->authorizeUsing()`, `->visible()` — the shared authorization, through `Gate`, wildcards included.
Do not add a role check of your own. Where: `->zones('sales')` (`->zones(null)` is the unzoned app),
`->resource('orders')`, `->page('index')`. Several matching tours: the lowest `->sort()` runs, the
rest wait for a later visit — one tour per audience, never one tour with branches.

**Show it again by changing `->since()`.** The stored value is compared for inequality, so any
different string re-runs it for everybody who finished the old one. Never rename the id for that —
the id is what acknowledgements are stored against. Storage is `wire-core.tours.preferences`
(default `session`; `database` plus the `wire-core::migrations` publish for "once, ever").
Below the mobile sheet breakpoint the panel docks to the bottom and the page scrolls each element
above it; what a phone hides (the sidebar drawer) is skipped and not counted. `TourStep::on('orders')`
puts a step on another page of the same zone — "Next" navigates there and the tour carries on; a step
whose page's route (`can:` middleware, via core's `AuthorizesUrls`) refuses this person is skipped. A tour
left halfway reopens at the step reached (stored per `since()`; cleared by finish, skip and replay). The
framework registers no tour itself.

**A tour may ask before it points.** `->welcome(TourWelcome::make()->heading('…')->text('…'))` opens it
with a centred card offering Start and Later. **Later is not Skip**: skip is recorded exactly as finishing
is, while later puts the tour down for that session and is counted — the one reaching `->postpone(int)`
(default `wire-core.tours.postpone`, 3) acknowledges it, so a greeting cannot return for ever.
`->postpone(0)` drops the Later button. The card is shown only when the tour starts from the top, never
when resuming. `TourWelcome::view('tours.welcome')` swaps the markup for your own, included inside the
tour's Alpine scope, where it has `greeting`, `begin()`, `later()` and the `welcome` payload and owns its
own `x-show`. Hooks: `tour-welcome`, `-heading`, `-text`, `-start`, `-later`.

**A tour is drawn by the layout, through `PageChrome`** — `wire-admin`'s renders it, and a layout of
your own must render the same two loops (body, and `PageChrome::USER_MENU`) plus `@wireStackScripts`
in the head, or nothing appears however well the tour is scoped. That registry is also where the
"Replay the tour" entry in the user menu comes from; it shows only on a screen a tour claims. For a
replay trigger anywhere else, return `app(TourState::class)->replayNow('id', auth()->user())` from the
action: it forgets the tour *and* answers with a redirect to the screen the tour runs on, built from the
tour's own scoping through the router — never from an address held in a public Livewire property, which
is writable from the browser. It answers null when the tour names no page of its own or the person may
not open it, and the tour is forgotten either way, so reload when null. `replay()` is the same without
the redirect, for a trigger on a screen the tour already claims.
`TourState::acknowledge('id', $user)` is the lever the other way, which is what a test uses to assert
somebody already-seen is left alone.

### JavaScript assets

Put `@wireStackScripts` once in the layout `<head>`. It emits every registered wireStack Alpine
controller (dropdown/tabs/wizard/editable-cell/fill-handle from core, plus whatever `table`,
`forms` and `sortable` registered), so they survive `wire:navigate`. Narrow it with
`@wireStackScripts('wire-table')` if you only want one package. Apps that do not add it still
work — each surface `@include`s its own asset partial as a fallback — but a SPA app **should**
add it: without it a bundle first reaching the page via `wire:navigate` may lose the race on the
cached Back/Forward path, where Livewire does not wait for newly injected head scripts before
initialising Alpine.

The tag itself is `nyoncode/laravel-package-toolkit`'s (`PackageAssets`); `@wireStackScripts` is a
thin alias for its `@packageAssets`, kept because it is already in consuming layouts. A package
declares its own bundles in its own `configure()`; core never learns about downstream packages:

```php
$packager
    ->bootedPackage(fn () => Bundle::serve('wire-table', self::ASSETS_PATH))
    ->hasAssets('dist', entries: [
        Bundle::make('wire-table-records.js'),
    ])
    ->hasAssetFallback(Bundle::servedByRoute('wire-table'));
```

`Bundle` (core) is the one place that knows what shape a wireStack bundle is: `classic()`, because
every bundle is an esbuild IIFE and the toolkit would otherwise emit `type="module"` — a module is
deferred and its top-level declarations never reach `window`, so the registrar below would register
nothing and every `x-data` would die with no error at the point of the mistake. It also removes the
`defer` that `classic()` adds by default, and adds `data-navigate-once`.

Entries are keyed by the **shipped filename**, not a short id. Delivery is the toolkit's: the mirror
copies `dist/` into `public/vendor/{package}` on first resolve — no `vendor:publish`, no build step
for consumers — and `hasAssetFallback()` points at the package's own `{package}.asset` route for the
app whose `public/` cannot be written. Without that fallback the renderer drops the tag silently.

**`Bundle::serve()` registers that route — never hand-write it.** It is the other half of the same
mapping: `servedByRoute()` reads a bundle id off a shipped filename to build the URL, `serve()`
turns that id back into the file, and one class owning both is what keeps them from drifting. It
carries the parts a hand-written `Route::get` gets wrong — a 404 rather than a 500 for a bundle the
package does not ship, an `[A-Za-z0-9_-]+` id pattern, and the immutable cache header that stops a
fallback the renderer reaches on every page from costing a request every page. Name the bundle
`{package}-{id}.js`, after the package itself, or anything else: all three round-trip.

**Register Alpine components unconditionally, never only inside `alpine:init`.** That event fires
exactly once per document, so a bundle arriving later (SPA navigation, a lazily rendered table, an
AJAX-loaded modal) would subscribe to an event that never fires again and register nothing —
`x-data="wireX(...)"` then dies with `wireX is not defined`. The canonical idiom, used by every
bundle in the repo:

```js
let registered = false
const register = () => {
    if (registered || ! window.Alpine) return
    registered = true
    window.Alpine.data('wireX', wireX)
}
if (window.Alpine) register()
else document.addEventListener('alpine:init', register)
```

The `registered` guard is load-bearing, not defensive: the directive and a per-surface partial can
both emit the same `src`, so the bundle may execute twice.

**Declaring the entry is not enough — the surface still has to include its own `@assets` partial.**
`@wireStackScripts` is *additive*: an app that never puts it in a layout is supported, and then the
declaration delivers nothing. A view whose `x-data` calls a factory from a bundle nobody delivered
evaluates against an empty registry and the component silently does nothing — no exception, no
console error at the point of the mistake. This is invisible to PHP tests, which read the markup and
find it correct; only `npm run verify:drivers` catches it. So a new controller bundle needs both: the
`Bundle::make()` entry, and an `@assets`/`@packageScripts` partial included from every view that
uses it (`wire-core::partials.floating-assets`, `wire-forms::partials.field-assets`).

Core interaction controllers are **never** lazy per-component — that is what causes the bug above.
Lazy is for heavy, optional bodies only: TipTap is the one case, and it stays outside the entry list
entirely, delivered by the field that needs it. Lazy-load bodies, never registrators.

### Browser-testing hooks

Every interactive control across the shared UI carries a stable `data-testid` (+ an accessible name/role where icon-only), so Pest v4 Browser Testing targets it at the user level: modals (`modal-close`, `slide-over-close`, `modal-cancel`/`modal-submit`/`modal-back`/`modal-next`, `confirmation-confirm`/`confirmation-cancel`, `modal-footer-action-{name}`), layout (`wizard-step-{i}`/`wizard-back`/`wizard-next`, `tab-{i}`, `section-toggle`, `callout-dismiss`), toasts (`toast-dismiss`, `toast-action-{i}`, `toast-expand`), the searchable select (`select-trigger`/`select-search`/`select-option-{value}`/`select-clear`), actions (`action-{name}` + header/bulk/menu variants), and infolist actions (`infolist-action-{name}`). Actions and options are also reachable by visible text/role.

### Icons & colors

Icons resolve by name through the `IconManager` (bundled Heroicons solid + `outline:` prefix). Use
`list-icons` to find a name. In a Blade view use `<x-wire::icon name="check" class="w-5 h-5" />`
— the component API, which also forwards Alpine/`data-*` attributes onto the `<svg>`. In **custom
column / entry / partial views** rendered per row, prefer the `icon()` helper —
`{!! icon('check', 'w-5 h-5') !!}` — a plain PHP function returning the cached
`IconManager` `<svg>` string (no per-row view render, unlike the component); pass `$attributes` (5th
arg) for an Alpine-bound icon. Never hardcode an inline `<svg>` (breaks theming). Colors and sizes are
semantic tokens owned by the Foundation palette.
`->color()` accepts the full Tailwind palette on every surface — the semantic roles (`primary`,
`success`, `danger`, `warning`, `info`, `gray`), every raw hue family (`blue`, `green`, `red`, `yellow`,
`cyan`, `slate`, `zinc`, `neutral`, `stone`, `orange`, `lime`, `teal`, `sky`, `indigo`, `violet`,
`purple`, `fuchsia`, `pink`, `rose`) and the adaptive achromatic endpoints (`white`, `black`), as a
string or the matching `Foundation\Colors\Color` enum case. The literal hues are NOT aliases: `blue` is
distinct from the re-themeable brand `primary`, `green` from `success`/`emerald`, `yellow` from
`warning`/`amber` (only `emerald`/`amber`/`secondary` remain true role aliases). `white`/`black` resolve
adaptively — dark in light mode, flipped in dark mode. Resolvers live in `HasColor`; unknown names fall
back to gray.

Every fluent token setter also accepts a canonical enum from `Foundation\Enums\` (interchangeable with
the string, so both forms are fine): `Breakpoint` (`sm`…`2xl`) for column `visibleFrom()`/`hiddenFrom()`/
`mobileBreakpoint()` + `stackedOnMobile()`, `Size` (`xs`…`xl`) for `size()`, `FontWeight` (`thin`…`black`)
for `weight()`, `Alignment` (`left`/`center`/`right`) for `alignment()`/`actionsAlignment()`, `IconPosition`
(`before`/`after`) for `->icon($icon, $position)`, `Placement` for `ActionGroup::dropdownPosition()`, and
`ModalWidth` (`sm`…`7xl`/`full`) for modal `width()`. Each enum owns its vocabulary (`values()`/`resolve()`)
and, where relevant, the literal Tailwind class its tokens map to — extend the enum, not a local `match`.

- **Resources and dashboards register from a list or from a folder.** `config('wire-core.resources')` /
  `dashboards` list classes; `config('wire-core.discover')` maps a namespace to a directory
  (`'resources' => ['App\\Resources' => app_path('Resources')]`) and every concrete class of the kind under it
  registers at boot, after the lists (a class in both registers once). It goes through the autoloader
  (`Foundation\Registration\ClassDiscovery`) — never scan a folder of your own for the same purpose.

## wire-forms

Build forms inside a Livewire component using the `WithForms` trait and a `form()` method:

    use NyonCode\WireForms\Forms\WithForms;
    use NyonCode\WireForms\Forms\Form;

    class EditUser extends Component
    {
        use WithForms;

        public array $data = [];

        public function form(Form $form): Form
        {
            return $form->statePath('data')->schema([
                Section::make('Profile')->schema([
                    TextInput::make('name')->required(),
                    Select::make('role')->options(Role::class),
                    Toggle::make('is_active'),
                ]),
            ]);
        }
    }

### Fields

Inputs: `TextInput`, `Textarea`, `Select`, `Checkbox`, `CheckboxList`, `Radio`, `Toggle`, `DateTimePicker`,
`TimePicker`, `ColorPicker`, `FileUpload`, `KeyValue`, `MarkdownEditor`, `RichEditor`, `TiptapEditor`,
`CodeEditor`, `OtpInput`, `Rating`, `Slider`, `Tags`, `Repeater`, `BelongsToSelect`, `MorphToSelect`,
`Hidden`. `TimePicker` picks a time on a clock dial confirmed with OK on a desktop, and from a slot list at `minutesStep()` (default 30) on a phone; `asTime()` picks it
on the same clock dial, set live without OK (steppers below the mobile breakpoint) — same stored value, different panel. `TimePicker`'s mode is locked, so
`asDate()`/`asMonth()`/`asDateTime()`/`mode()` on it throw rather than return a calendar.
Display: `Alert`, `Html`, `Placeholder`, `ViewField`. Layout: `Section`, `Grid`, `Fieldset`,
`Tabs`/`Tab`, `Wizard`/`Step`. All tab/step panels stay in the DOM, so nested fields flatten and
validate together on submit; the tab bar scrolls horizontally on mobile and the wizard names the
active step under its indicator. Inside a Livewire host the wizard additionally validates **per
step**: "Next" calls the `validateWizardStep()` endpoint and only advances when the current step's
fields pass, a failed full-form submit jumps to the first step with an error, and steps
hidden/shown by a `visible()` Closure re-sync the indicator mid-form. Multiple wizards on one host
are addressed by name (`Wizard::make('signup')`).

### Conventions

- Fields are `::make($name)` where `$name` is the state key under the form's `statePath`.
- A custom field starts from `php artisan make:wire-field MoneyInput` — `app/Forms/Components/MoneyInput.php` (`getStateType()` + `viewName()`, no suffix added) and `resources/views/forms/components/money-input.blade.php` inside the `wire-forms::partials.field-wrapper-*` partials. Stubs: `wire-forms::stubs`.
- Validation lives on the field: `->required()`, `->rules([...])`, `->minLength()` etc.
- `->rules()` accepts a Closure (or Closure entries in the array), evaluated with `$get`/`$set` for rules that depend on live sibling state.
- Options accept arrays or an enum class: `->options(Status::class)` (shared `HasOptions`).
- Reactivity is opt-in with `->live()`; default fields use deferred `wire:model`.
- `->nativeSubmit()` renders the fields for the browser's own POST instead of Livewire: each carries `name` and `value=old(...)`, errors come from the shared `$errors` bag. The form element, its action, its CSRF token and its submit button stay in the view — the form object renders fields only. A field must implement `Contracts\SupportsNativeSubmit`; a schema containing one that does not throws `FormConfigurationException` at render, because a `wire:model`-only input has no `name` and would post nothing. `TextInput`, `Checkbox`, `Hidden` and `OtpInput` implement it — the fields the signed-out screens are made of. A checkbox posts a constant `value="1"` and answers by being present, so "off" is the missing key; `Hidden` is how a native form carries a value nobody typed, such as the reset token; `OtpInput` renders a real named input beside its boxes, so a code challenge is answerable with JavaScript off. `live()`/reactive fields, server-searching `Select`, `FileUpload` and `Repeater` cannot. The mode is applied after `form.configuring`, so a field a hook added is switched — and refused — like a declared one. Used by every signed-out screen in `wire-module-auth`, which posts to Fortify.
- `Select`/`Radio`/`CheckboxList` share the same options API.
- `FileUpload::acceptedFileTypes([...])` is checked **on the server** too (`Validation\Rules\AcceptedFileTypes`: an entry with a slash is a MIME type, `image/*` included; anything else an extension, with or without its dot; a file passes when either kind accepts it). Only a fresh upload is checked — a stored path on an edit form passes — and on a `multiple()` field the rule sits on each file. `maxSize()`/`minSize()` are server rules as well. Reuse the rule for any other upload path rather than writing `mimes`/`mimetypes` by hand.
- `DateTimePicker`/`TimePicker` **display** in `config('wire-forms.date_format' | 'time_format' | 'datetime_format')` for their mode unless `->displayFormat()` says otherwise; the **stored** value keeps the state shape (`Y-m-d`, `H:i`, `Y-m-d\TH:i`), which is what `getFormat()` answers. A field that keeps seconds shows its raw state when the configured format has no `s`. `wire-forms.first_day_of_week` drives the calendar and `DateRangePicker`'s "this week".
- Picker bounds: `minDate()`/`maxDate()` bound the days (a time they carry binds on that one day only); `minTime('06:00')->maxTime('18:00')` is a daily window that binds on **every** day of a time or datetime picker (and on both ends of `DateRangePicker::withTime()`), the stricter of the two winning on a bound day. Both are greyed out in the panel and repeated server-side by `DateWithinBounds`; `DateBoundary::timeOfDay()` reads the window and throws on anything but `H:i`/`H:i:s`.
- From the mobile breakpoint up the picker panel is the desktop one (month/year grids behind the header, the clock dial, arrow/PageUp/Home keys, Today/Now); below it the phone's sheet, steppers or `touchOnMobile()` wheel are unchanged. `DateRangePicker` draws one `from – to` box over a two-month panel with the presets beside it on desktop (`wireDateRangePicker`, entangled to both ends' state paths) and its two pickers on a phone — which is why those two are `belowBreakpointOnly()` and say `aria-required` rather than `required`.
- `FileUpload` is **store-on-submit** (orphan-free): `InteractsWithFileUploads` composes Livewire `WithFileUploads`; an upload stays a pending `TemporaryUploadedFile` (Livewire 4 appends new uploads onto a `multiple()` field's existing entries itself — do NOT add an `updated()`/`updating()` hook that merges them again, it double-counts what is already there; a single field keeps the newest) and is moved to the `disk()`/`directory()` only on **save** by a `SaveHandler` step (`FileUpload::storeUploadedFile`). The view lists stored paths + pending uploads with index-based `removeUploadedFile`, which writes through `StateContainer::writeInto()` so it works in an action modal too; `->deletable(false)` is read-only.
- `Radio` has display variants: `->cards()` (selectable cards, `->inline()` for a row,
  `->hideIndicator()` to drop the dot), `->segmented()` (pill over a track), and `->buttons()`
  (separate buttons, selected filled; `->inline()` for a row). `->icons([value => name])` and
  `->colors([value => color])` add per-option icons/colors (every variant) and are auto-derived
  from a `HasIcon`/`HasColor` enum passed to `->options(Enum::class)`. The `segmented`/`buttons`
  variants take a size via the shared `HasSize` API (`->sm()`/`->md()`/`->lg()`), and `->color(...)`
  sets one group accent (shared `HasColor`). On mobile the segmented track stretches its segments
  to the full width; from `sm` up it keeps intrinsic width.

### Browser-testing hooks

Every field is wrapped with `data-testid="form-field-{statePath}"` (+ `data-field`), and its label is associated (`<label for>`), so Pest v4 Browser Testing can scope to a field and act on it at the user level. Naming convention: a control is `form-{type}-{statePath}` and sub-controls append `-{action|value|index}` — every interactive control down to the micro level is tagged:
- `form-toggle-{path}`, `form-checkbox-{path}`, `form-slider-{path}`, `form-button-{path}`
- `form-radio-{path}-{value}`, `form-checklist-{path}-{value}` (+ `-select-all` / `-deselect-all` / `-search`)
- `form-repeater-{path}-add|remove-{i}|reorder-{i}`, `form-keyvalue-{path}-add|remove-{i}`
- `form-file-{path}-dropzone|remove-{i}`, `form-tags-{path}-remove-{i}`
- `form-datetime-{path}-trigger|prev-month|next-month|day-{d}|hours-up|hours-down|minutes-up|minutes-down|seconds-up|seconds-down|clear|done`
- `form-color-{path}` (+ `-hex` / `-swatch-{color}`), `form-rating-{path}-star-{n}`, `form-otp-{path}-{i}`
- `form-editor-{path}` (body) + `-{command|index}` toolbar + `-write` / `-preview` tabs
- field/affix/hint actions `field-action-{path}-{name}`

The searchable Select combobox uses the shared `select-trigger` / `select-search` / `select-option-{value}` / `select-clear`, with create/edit-option modals `select-create-save|cancel` / `select-edit-save|cancel`.

### Reactive patterns

Every field closure (`visible()`, `hidden()`, `disabled()`, `afterStateUpdated()`) is evaluated with
Filament-style accessors resolved against the live state bag — works identically in a standalone
`WithForms` form and inside a table action modal:

- `$get('sibling')` — read another field's current value.
- `$get()` — read this field's own value live (reflects a `$set` made earlier in the same closure).
- `$set('sibling', $value)` — write another field (StateContainer-safe, even in action modals).
- `$state` — this field's value, snapshotted at call time.

Conditional field driven by a sibling:

    Select::make('type')->options(['business' => 'Business', 'person' => 'Person'])->live(),
    TextInput::make('vat_id')->visible(fn ($get) => $get('type') === 'business'),

Fluent conditioning shortcuts avoid the Closure for the common "field equals value" case
(pass an array to match "is one of"):

- `->requiredIf('type', 'business')`, `->requiredUnless('type', 'person')`, `->requiredWith('company')`
- `->visibleWhen('type', 'business')`, `->hiddenWhen('type', 'person')`, `->disabledWhen('locked', true)`

`visibleWhen`/`hiddenWhen`/`disabledWhen` are shared Foundation helpers (also on columns, filters,
actions); the `required*` helpers live on the field. Both resolve reactively against live sibling state:

    Select::make('type')->options(['business' => 'Business', 'person' => 'Person'])->live(),
    TextInput::make('vat_id')->visibleWhen('type', 'business')->requiredIf('type', 'business'),

`afterStateUpdated()` runs a callback after the field's value changes (auto-enables `live()`):
the callback receives `$state` (new value), `$old`, `$get`, `$set`, `$component`.

    TextInput::make('type')->afterStateUpdated(
        fn ($state, $set) => $set('vat_id', $state === 'business' ? null : ''),
    );

Hidden fields are skipped during validation, so a `required()` rule on a field hidden by
`visible(fn ($get) => …)` never blocks submit.

Live validation is opt-in per field: `->validateLive()` (validates on each change) or
`->validateOnBlur()` (validates when focus leaves). The host validates just that field during the
reactive roundtrip and refreshes only its error bag entry — the rest of the form is not flagged.
Conditional rules (`requiredIf()` etc.) are honoured live. Cross-field Laravel string rules like
`required_if:other,value` still validate on submit; use `requiredIf()` for the reactive equivalent.

- **`Form::fieldPartials()` answers a field commit with the fields that moved, not the host view.** Opt-in.
  A `live()` field re-renders the whole component otherwise: 19 860 B of HTML to carry one field's 1 562 B on a
  12-field form (12.7× raw, 2.3× gzipped). Three outcomes, and the common one sends **nothing at all** — a
  field's value rides `wire:model` and the data payload rather than its markup (a `TextInput` renders no
  `value` attribute), so an ordinary keystroke moves no markup anywhere. Markup moves only when something
  *derived* does: a sibling whose `options()`/`label()`/`helperText()` closure reads the state, an error
  appearing, a field becoming disabled — those come back as regions. A `visibleWhen()` sibling appearing or
  disappearing changes the SET of fields, which is a shape change no region describes, and falls back to a
  full render on its own. **It decides by comparing rendered markup, never by reasoning about dependencies** —
  a graph would have to understand every closure a field's config can hold, and the one it missed would show
  a stale value silently. **What you trade:** the host's own view does not re-render on a covered commit, so
  anything it draws outside the form (a live preview of `$data`, a heading counting filled fields) keeps its
  previous value until the next full render. Tell consumers that before suggesting the flag.

All of this reactivity works for fields inside `Repeater` items too — `afterStateUpdated()`,
live validation, field actions, remote search and conditional visibility (`visibleWhen()` /
`visible(fn ($get) => …)`) resolve per item, and `$get`/`$set` read/write that item's own bag
(so `$set('slug', …)` on row 2 touches only row 2). `Repeater::itemLabel(string|Closure)` names each
item block next to its number (`fn(array $state, int $index): ?string`, e.g. from a field) — pair with a
`->live()` field to update as the user types.

Selects: `Select` supports server-driven options (`getSearchResultsUsing()` remote search,
`getOptionLabelUsing()`, `preload()`) and create/edit-option modals (`createOptionForm()` +
`createOptionUsing()`, `editOptionForm()` + `fillEditOptionUsing()`/`updateOptionUsing()`) —
both work in standalone forms and inside table action modals, and a created/edited option is
selected and merged into the open combobox immediately (no page refresh). The option schema is
a full form schema, not a field list: a `Wizard` inside `createOptionForm()` gates its steps
(errors land on `createOptionFormData.*`), a nested `Select` reaches remote search, and field
actions resolve — the mounted option form is enumerated as a host form. Give that wizard
`->navigation(false)` and the modal footer drives it (Back/Next beside Cancel, submit only on the
last step); name it, since footer and wizard pair up by name. Opening an option modal
from inside an option form is refused (one mounted path per kind). Both option modals are
configured through the canonical `Modals\Modal` config object —
`->createOptionModal(fn (Modal $modal) => $modal->heading(…)->description(…)->icon(…)->width('2xl')
->closeOnClickAway(false)->stickyFooter()->submitLabel(…)->cancelLabel(…))`, and the same for
`->editOptionModal()`; `createOptionModalHeading()`/`createOptionModalWidth()` (+ `editOption…`
twins) are shorthands writing into that same object. The modal's id/wire:model are not
configurable — they key the teleport Livewire morphs by. The combobox honours
`->live()` — add it when siblings react to the selection. `BelongsToSelect::searchable()`
without `preload()` searches the related table on the server automatically (title-attribute
`like`, limit 50); `preload()` ships the full option list and filters client-side.

    TextInput::make('email')->email()->required()->validateLive(),

Layout components (`Grid`, `Section`, `Fieldset`, …) receive the same `$get`/`$set` accessors in
their `visible()`/`hidden()` closures, so you can show or hide a whole section based on sibling state:

    Section::make('Billing')
        ->schema([TextInput::make('vat_id')])
        ->visible(fn ($get) => $get('type') === 'business'),

### Field-level actions

Attach an interactive `Action` to an input via `suffixAction()`, `prefixAction()` or `hintAction()`.
The action's callback runs on the server with the same `$get`/`$set`/`$state` context as
`afterStateUpdated()` — ideal for lookups (ARES, address verification) or deriving one field from
another:

    TextInput::make('title')->suffixAction(
        Action::make('to_upper')
            ->icon('heroicon-o-arrow-up')
            ->action(fn ($get, $set) => $set('title', strtoupper((string) $get('title')))),
    );

For a standalone, design-system-styled button bound to a closure (instead of raw `Html::make()`),
use the `Button` field. Presentation (`label`, `icon`, `color`, `size`, `outlined`) mirrors actions:

    Button::make('generate_slug')
        ->label('Generate slug')
        ->icon('heroicon-o-sparkles')
        ->action(fn ($get, $set) => $set('slug', Str::slug((string) $get('title')))),

Both work in standalone `WithForms` hosts and table action modals; the host's `callFieldAction()`
endpoint re-resolves the field and runs the closure.

### Prefill a form from an action

Action modal forms read/write the `modal.action.formData` bag. The schema **self-seeds**: every
field starts at its `->default()` or a type-correct blank (`[]` for `CheckboxList`/`Tags`/multi-`Select`,
`false` for `Toggle`, else `null`), so you never hand-list a field just to give an array input its
`[]` — it no longer collapses. Layer record/context prefill on top with `fillFormUsing()` (the
callback receives the record; `null` for header actions); its values override the seed:

    EditAction::make()
        ->form([
            TextInput::make('name')->required(),
            Select::make('role')->options(Role::class)->default('member'),
        ])
        ->fillFormUsing(fn ($record) => [
            'name' => $record->name,
            'role' => $record->role->value,
        ]);

`fillFormUsing` (and a record's stored value) win over `->default()`; the seed only fills keys the
data omits, so an intentional `null` from the record is never overwritten by a default. To opt one
field out of that rule — treating an edit-mode `null` or empty string as unset so its default fills
there too — add `->defaultOnNull()`. Use it only where `null` is not a value the user can choose
(e.g. a column that must always carry a value); otherwise leave it off so a cleared value survives.

Add extra footer buttons that read/write the in-progress form without submitting it via
`modalFooterActions()` — each `ModalFooterAction` callback gets the live `$data` bag and a `$set`
writer (`->submitsForm()` validates first, `->closesModal()` closes after):

    ->modalFooterActions([
        ModalFooterAction::make('generate_slug')
            ->action(fn ($data, $set) => $set('slug', Str::slug($data['name'] ?? ''))),
    ]);

### Standalone actions (`WithActions`)

Run modal/slide-over/wizard/confirmation actions — with forms, validation and the full
lifecycle — in ANY Livewire component, no table required. Add the `WithActions` trait, declare
named actions in `actions()`, and drop the modal host in the view once:

    use NyonCode\WireForms\Concerns\WithActions;

    class EditPanel extends Component
    {
        use WithActions;

        protected function actions(): array
        {
            return [$this->editOfferAction()];
        }

        protected function editOfferAction(): Action
        {
            return Action::make('editOffer')
                ->slideOver()
                ->form([TextInput::make('name')->required()])
                ->fillFormUsing(fn () => ['name' => $this->offer->name])
                ->action(fn (array $data) => $this->offer->update($data));
        }
    }


    {{-- view --}}
    <x-wire-actions::button :action="$this->editOfferAction()" />
    <x-wire-actions::modal-host :component="$this" />


- The button auto-derives `wire:click="mountAction('<name>')"` when no `wire-click` is given
  (an explicit one, or a `url()` action rendered as a link, wins). Hidden actions render nothing.
- Livewire methods added: `mountAction($name, ['record' => $model])` (opens the modal, or runs a
  plain action immediately), `callMountedAction()` (validate + run), `unmountAction()`,
  `nextActionModalStep()`/`prevActionModalStep()` (wizards), `callModalFooterAction($name)`.
- Modals stack as a live frame array in the public `mountedActions` property; each open modal's
  form-data bag lives at `mountedActions.{depth}.data`. Field actions, `createOptionForm` and
  `fillFormUsing` all work exactly as in a table modal.
- Nested modals can return data: action/footer callbacks receive `$set`/`$setParent`/`$setFrame`/
  `$parentData`/`$arguments` bindings, so a child sub-form can write straight back into its parent.
- Same engine backs `WithTable`: the form-agnostic core lives in
  `NyonCode\WireCore\Actions\Concerns\InteractsWithActions`, the form bridge in
  `NyonCode\WireForms\Concerns\InteractsWithActionForms`. Prefer this over reimplementing action
  handling on a component.

Use `describe-component-api` to see a field's full fluent surface.

- **Warn before a form's unsaved input is left behind** with the fields bundle's controller on the element that
  owns the form: `x-data="wireUnsavedChanges({ path: 'data', method: 'save' })"`. It compares the state bag
  with the last successful save (so typing and putting back is no change), holds off while the save is in
  flight (a redirect from inside it never prompts), and answers `beforeunload` and `wire:navigate`. The
  panel's create and edit pages already arm it — never add a `beforeunload` listener of your own.

## wire-modules

Seven ready-made areas, each its own composer package. A module is **not a new kind of thing** — it is a
plugin that names resources, dashboards and one menu heading, and everything downstream reads it through
the catalogue. `composer require` is the whole installation: the package's provider registers the module
with `PluginManager`, `WireCoreServiceProvider` spreads what it declares into the resource registry, the
dashboard registry and the navigation groups, and `Route::wireResources()`, the sidebar and the search
palette find the area without being told.

| Module | Package | What arrives | Needs |
| --- | --- | --- | --- |
| Users | `wire-module-users` | The user resource, its pages, a profile screen, roles where the app has them | A user model; `nyoncode/laravel-permission-extended` for the role surfaces |
| Auth | `wire-module-auth` | Sign-in, password reset, e-mail verification, the 2FA challenge, **Sign out**, one-time codes | `laravel/fortify` |
| Settings | `wire-module-settings` | A typed settings table, its cache, and the screen over it | A database connection |
| Notifications | `wire-module-notifications` | The history behind the notification bell, as a table | Laravel's `notifications` table |
| Audit | `wire-module-audit` | A screen for the trail `wire-core` already records | `HasAuditable` on the followed models |
| Media | `wire-module-media` | Uploads, folders, previews, and a picker for forms | A filesystem disk |
| Companies | `wire-module-tenants` | Registering a company, its profile, inviting and managing members | A tenant zone; `HasTenants` on the user model |

- **No module requires another**, and none of them requires the admin shell. Write code that works when
  only one of them is installed — `describe-module` reports which are actually registered here.
- **Every module is removable two ways**: `composer remove` takes the area out, and the module's own config
  drops or replaces what it contributed while the package stays. Where a module ships a screen over
  something the application already owns — a settings tab, a user resource — **the application's own
  declaration wins** over the contributed one. Never patch a module's classes in place to change a screen.
- **Permissions always go through `nyoncode/laravel-permission-extended`**, never bare
  `spatie/laravel-permission`. The wildcard matching, the super-admin gate and the permission-change events
  these screens assume live only in the extended package, so a user model carrying Spatie's `HasRoles`
  directly is deliberately not detected and the role and team surfaces stay off.
- **A module's screens are a route group the application places, never routed by its provider**:
  `Route::wire('auth-codes')` for the code flows, `Route::wire('tenants')` for company registration and
  invitations, beside `Route::wire('panel')` — in `routes/web.php` inside the application's own group, or as an
  entry of `wire-core.routes.groups`. Middleware, `can:`, prefix and domain come from that group or entry; the
  call returns its routes keyed for one screen alone (`Route::wire('tenants')['register']->middleware('can:tenants.create')`,
  or `'routes' => ['register' => ['can' => …]]` in config). Never put these in a `Route::name()` group — mails
  link to their names and it throws. A screen that 404s: check that something places its group. A module
  writing its own: implement `ProvidesRoutes`, register it with `RouteGroups::instance()->register(...)`.
- **Fortify owns the security, `wire-module-auth` owns the screens.** Anything Fortify has an answer for
  stays Fortify's. One-time codes are the only authentication this stack owns: four switches under
  `wire-module-auth.codes`, all off by default, each routed by the `auth-codes` group only while on.
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
- **Two-factor and teams in the users module are `auto` switches that look for the thing itself** — Fortify
  for 2FA, the permission package for teams — and stay off when it is absent. Do not force them on with a
  config flag the feature cannot honour.
- These are the packages whose views are **published for a consumer to open and edit**, so they use the
  `<x-wire::*>` Blade tags on purpose; they render once per page, not per row. That is the opposite of the
  rule for the render engine (`wire-core`/`forms`/`table`/`panels`/`sortable`), which must not depend on
  Blade components.
- **Writing your own module?** `packages/module-users` is the reference. Declare it like any other module;
  a package ships one by registering it from its provider instead of from the application's config.
- **`php artisan wire:user` makes an account; the installer's step makes only the first; `wire:assign-role
  <email> --role=… [--team=…]` gives roles to one that exists.** All three go
  through `WireModuleUsers\Support\Accounts` — the model (`wire-module-users.model`), the column
  names (`.fields`) and the roles are the *application's*, so never hard-code `users`, `name` or
  `email` in new code. `Accounts::superAdminRole()` is the name the permission gate checks; inventing
  one makes an administrator the gate does not recognise. An address or password taken from a person or
  an option goes through `Accounts::emailProblem()` / `passwordProblem()` first — the rules the user
  screens hold them to.
- **A setup step that publishes migrations publishes them through `Foundation\Setup\RedundantMigrations::around()`**,
  and one that writes an answer into a file asks it through `Foundation\Setup\Answers`. A published
  migration whose tables already exist, or a quote written into PHP source, is an application that
  no longer migrates or no longer boots.
- **With tenancy, a team is a project of one company**: make the team model `BelongsToTenant`, and
  the switcher lists the current company's projects only, the current team is kept per company
  (`wire.team.{tenant}`), and `Teams::followTenant()` re-points the permission registrar on `TenantEntered` —
  `SetCurrentTeam` runs before a tenant zone enters the company. Never keep a second "current team" beside it.
- **The super-admin is never a role among others.** It can do everything, in every team, so it is given
  only through `Accounts::makeSuperAdmin()` — a *global* assignment (`assignGlobalRole()`), which is all
  the permission gate honours with teams on — from `--super-admin` or the installer's confirmation.
  `Accounts::assign()` refuses it, `Roles::options()` never lists it, and a user form save keeps it
  rather than stripping it. Ask `$user->hasGlobalRole(Roles::superAdmin())`, never `hasRole()`.
- **With teams, a screen over people is scoped to the current team** through `Teams::scopeMembers()` —
  on the query (`modifyQueryUsing`), never a filter, so row actions resolve records through the same
  scope — and a record page finds its record the same way (`WirePanels\Resources\Concerns\ResolvesScopedRecord`,
  404 outside). That trait lives in `wire-panels`, not in this module: a scoped list without a scoped
  page is a URL that reaches past it, which is every resource's question and not only this one's.
  Whether somebody works across every team is `Teams::seesEveryTeam($ability)`: a super-admin, or the
  ability held through a *global* role (`hasGlobalPermission()`), never the same ability from a team role.
  Roles follow the same line through `Teams::scopeRoles()` (global roles + the current team's), a new
  role gets its team from `Teams::placeNewRole()`, and whether a role may be edited or deleted is
  `Roles::mayChange()` — never the super-admin role, the admin role (`wire-module-users.admin_role`)
  only for a super-admin.
- **Administrators are roles, not flags.** `team-admin` (given inside a team) and `admin` (given with
  `wire:assign-role --role=admin --global`) are made on first assignment with `Permissions::abilities()`
  — exact ability names, never `users.*`, which the permission layer reads as a name when granted.
  Resolve a role by name through `Accounts` (global or the team's own), never a bare `firstOrCreate`.
- **Whose account may be touched: `Support\AccountGuard`.** `mayEdit()` (never a super-admin's, unless
  you are one), `mayDelete()` (never the last super-admin; not a team's manager), `mayRemoveFromTeam()`,
  `mayChangeCredentials()` (not a team's manager). A new action on an account asks it; taking a role
  away is `wire:revoke-role`, which refuses the last super-admin without `--force`.
- **Nobody hands out more than they hold: `Support\RoleGrants`.** Any screen or action that writes a
  role's permissions or an account's roles offers `RoleGrants::mayGrantPermission()` /
  `mayGrantRole()` and writes `clampPermissions()` / `clampRoles()` — narrowing, not refusing, so
  what the actor may not change stays as it was. Never a local "is this allowed" beside it.

## wire-panels

The application owner layer: one entity, declared once, with the surfaces it exposes.

- **A resource is several small contracts, not one class.** `DescribesResource` (`wire-core`) is identity — `key()`, `modelClass()`, `label()`, `pluralLabel()`, all **static** — plus whichever surfaces apply: `ProvidesResourceForm` (`wire-forms`), `ProvidesResourceInfolist` (`wire-core`, beside Infolists), `ProvidesResourceTable` (`wire-panels`). A resource implements only what it has, so a read-only log declares identity + table and a page needing a form cannot be handed one that has none.
- **Each contract ships in the package owning the type it names**, which is what keeps installs honest: a resource with a form and no list needs `wire-forms` and nothing else — identity comes from `wire-core`, which forms already requires, so it never pulls in a table package. `wire-panels` requires table + forms + core and nothing requires it.
- **Static identity, instance surfaces**, and that is mechanical not stylistic: a menu asks for a label and `ResourceRegistry::forModel()` routes a model to its owner *before anything is instantiated*, so metadata must answer without an instance. Surfaces receive a builder the caller already wired to its host (same shape as `RelationManager::table()`) and hand it back.
- **Use `DescribesRecords` and declare only `modelClass()`.** Key, label and plural derive from the model (`App\Models\OrderLine` → `order-lines` / `Order Line` / `Order Lines`, irregular plurals via the inflector), so the key the registry routes on and the label a menu shows cannot drift. Override one answer without restating the rest. A resource over a non-Eloquent `DataSource` returns `null` and derives from its own class name; it registers and lists normally, it just is not findable by model.
- **Register in `config('wire-core.resources')`**, or name a folder in `config('wire-core.discover.resources')` (`'App\\Resources' => app_path('Resources')` — every concrete `DescribesResource` under it registers at boot, after the list; `discover.dashboards` likewise), or `app(ResourceRegistry::class)->registerMany([...])` at runtime. Re-registering one class is a no-op (config merging and a twice-booted provider both do it); two different classes on one key throws, because the second would silently take over routing for the first. It is a registry, **not a panel** — no routing, no URL shell, no navigation tree — and everything it answers comes off the static contract, so building a menu never composes a table.
- **`ListPage` composes `WithTable`**, so polling, row partials, gestures and exports arrive unchanged. Set `protected static ?string $resource` and the heading defaults to the plural label; or write your own `table()` and use no resource at all — both paths are first class. A page left *half* declared (no resource **and** no `table()`, or a resource with no `ProvidesResourceTable`) throws rather than rendering an empty table, because empty reads as "no records" instead of as a mistake. Pages own no routing; mount them like any Livewire component.
- **Four pages, all standalone-capable.** `ListPage` (`WithTable`), `CreatePage` / `EditPage` (`WithForms` + `WithActions`), `ViewPage` (renders an `Infolist`, composes `WithActions` and no form/table trait — read-only means no state to bind and nothing to submit). Set `protected static ?string $resource`, or build the surface on the page and use no resource; both are first class. A page left *half* declared throws rather than rendering empty, because empty reads as "nothing here" instead of as a mistake.
- **Edit and view take a record KEY, not a model.** `@livewire(EditOrder::class, ['record' => $order->getKey()])`. Mount arguments land in the Livewire snapshot, so a hydrated model there is larger than the key and stale by the next request; the key travels and `resolveRecord()` looks it up per request. Override it for a soft-delete scope, a tenant guard or a non-Eloquent source. A resource with `modelClass() === null` cannot resolve a key and says so.
- **One `form()` serves create and edit** — a create form and an edit form that drift apart is the failure the shape prevents. Persistence stays the form's (`Form` owns validate → mutate → hooks → persist → notify); the page binds the model and calls `save()`. Non-Eloquent writes go through `Form::using()` in the resource's own `form()`, and the pages are unchanged.
- **The page owns host wiring, the resource does not.** Create/Edit declare `public ?array $data` and apply `statePath('data')` before handing the form to the resource — the same division that lets `table()` know nothing about the component rendering it. A resource needing another path sets one in `form()`, which runs after and wins.
- **Every new concrete `WithTable`/`WithForms` host needs a `phpstan.neon` excludePaths entry** (runtime magic: `$this->form` comes from `WithForms::__get`). `ViewPage` and `Page` are on it too — they compose `WithActions` through `HostsPageActions`, and analysed as classes they drag the action engine in.
- **Header actions: `protected function headerActions(): array` on any page**, `null` entries skipped, `ActionGroup` allowed. They run through the engine the page already has — **never add a second one**: the list's go through `WithTable` (its `findHeaderAction()` looks at the page's first; click = `HeaderActionClickResolver`), the others compose `HostsPageActions` (`WithActions` + `mountAction()` that passes the page's record, click = `MountActionClickResolver`). The list offers *New* by default (`createHeaderAction()`, a link that asks exactly what the create route's `can:` asks, keep it with `...parent::headerActions()`); **Delete is opt-in** — `$this->deleteHeaderAction()` on edit/view — because who may delete what is the application's rule: the model policy's `delete()` decides, without a policy whoever may open the edit page, with no edit page nobody. A module list that drew its own `HeaderAction::make('create')` in the table now draws two.
- **Create/edit pages warn before unsaved input is lost** (`wireUnsavedChanges` from the forms bundle, on the page's `<form>`; state compared with the last successful save, held off while `save()` is in flight). Off per page with `protected function warnsAboutUnsavedChanges(): bool { return false; }` — never a second `beforeunload` listener of your own.
- **List tabs are `tabs(): array` of `ListTab`** (`query()` narrows the *base* query by wrapping the table's `modifyQueryUsing()`, never replacing it; `showCount()` counts over that same base scope; `$activeTab` in the URL as `?tab=`). Never filter a list by a tab inside `table()` — the tab is applied in `getTable()` so it holds on a page with its own table. **Page widgets** (`headerWidgets()`/`footerWidgets()`) are drawn, not hosted: a polling, lazy or action-bearing widget belongs on a `DashboardPage`.
- **Trash is a resource's decision: `ManagesTrashedRecords`** (marker; the model must use `SoftDeletes`, or it refuses). List: `trashed` filter + Restore/Force delete per row and in bulk (bulk touches only trashed records). Record pages: resolve `withoutGlobalScope(SoftDeletingScope)`, hide `deleteHeaderAction()` on a trashed record, offer `restoreHeaderAction()`/`forceDeleteHeaderAction()`. Never add a second trashed lookup of your own — `TrashedRecords::managedBy()` is the one question.
- **A small resource is one `ManagePage`** (`pages()` = `['index' => ManageX::class]`): create and edit are modals over the list rendering the resource's `form()`, saved with `Model::create()`/`update()` — not the page form lifecycle, so relationship repeaters, optimistic locks and `Form::using()` need real create/edit pages. Policy (`create`/`update`/`delete`) when the model has one, otherwise no check (the route guards).
- **Start from the CLI, then edit**: `php artisan make:wire-resource Order [--generate --view --simple --soft-deletes --register]`, `make:wire-page TaskBoard [--resource=Order]`, `make:wire-relation-manager Order items`, and `php artisan wire:resources [key]` to see what is registered and routed (per zone, with permissions). Each ends by reading the app: *Ready* with the URL when the class is registered (config, `--register` or a discovered folder) and something routes it (a `Route::wire('panel')` / `Route::wireResources()` call, or a panel entry of `wire-core.routes.groups`), otherwise only the missing step — act on that line rather than re-deriving it. Generators never overwrite without `--force`; templates publish with `vendor:publish --tag=wire-panels::stubs`.
- **A record that lives inside another is a `NestedResource`** (`parentResource()` + `parentRelationship()` on the parent model): pages route under `{parent prefix}/{parent}/…`, the list/record lookup/create all go through `$parent->{relationship}()` (a foreign line is a 404), the trail walks through the parent, and the parent record gets a tab to the list automatically. One level only; create needs `hasMany`/`morphMany`; no `ProvidesNavigation` on a nested resource (no URL without a parent). Never hand-scope a child list by a request parameter — this is the owner of that rule.
- **Register a page of your own like a resource** — `config('wire-panels.pages')` or `config('wire-core.discover.pages')` — and it is routed at its `key()` by `Route::wireResources()`, listed in the menu and in `wire:resources`. Configure it with statics (`$slug`, `$navigationLabel`, `$navigationIcon`, `$navigationGroup`, `$navigationSort`, `$permission` — which also hides the menu entry — and `$shouldRegisterNavigation`). Never invent a model-less resource just to own a standalone page.
- **A page of your own is `Pages\Page`** — `protected static string $view` for the content, the heading/crumbs/header actions/modal host around it; implement `ProvidesBreadcrumbs` for a trail; route it with `RoutePage::make(X::class)` in an owner's `pages()`. Composing `HostsPageActions` on your own component gets the same without extending anything.
- **Navigation is static too.** `ProvidesNavigation::navigation(): NavigationItem` (wire-core) — a menu is built from every registered resource at once, so instantiating each to ask what it is called would compose a table and a form per entry. A resource without it is still registered and routable, just not in the menu (what an internal or nested resource wants). `NavigationItem` is built on the canonical `HasLabel`/`HasIcon`/`HasVisibility` concerns — **never declare a second label/icon vocabulary beside them** — and adds only what a menu needs: `group()`, `sort()`, `badge()`, `activeWhen()`. A badge Closure resolves on every read and is never cached; a count of unshipped orders is wrong the moment it is. **An entry need not name itself**: `NavigationItem::make()` beside `->icon()`/`->group()` is the ordinary shape, and the menu falls back to the resource's `pluralLabel()`, so a label is written once. **Which entry is active is `ActiveNavigation`'s** (wire-core `Core\Resources\Navigation\`), built once per page render — `ActiveNavigation::current()`, then `isActive($item, $key)` / `isExactly($item)` / `hasActiveChild($item)` / `ariaCurrent($item, $key)`. Three rules, first wins: what `activeWhen()` declared, then the **registered key** against the route's (so the Orders row stays lit on `wire.orders.edit`), then the URL, **exactly** — normalised for a trailing slash and for relative-vs-absolute, never a prefix match. A prefix default cannot tell a section from the shell's own mount path, so a `Home` entry at `/admin` would be lit on every page under it for ever; the entry that means a section says `->activeWhen('settings/*')` (matched against the path **and** the route name), and declaring it **replaces** the convention rather than adding to it. `aria-current` splits the same reading: `page` only for the entry whose URL *is* the URL being rendered, `true` for the branch you are inside — **never both on one screen**, because a record's tabs say `page` above the form.
- **An entry that belongs under another names its parent: `NavigationItem::make()->parent(ProductResource::class)`** (a registered key or its class; `Page` has `$navigationParent`). `Workspace::navigation()` moves it under the parent — its own `group()` then no longer applies — and repeats a parent that has a URL as its first child, because a branch is a disclosure, not a link. A parent that is registered but not in this menu (hidden, `linkedOnly`, removed by a hook) leaves the child in its own group; an unknown parent, a self-parent or a third level throws `NavigationParentException`. `items()` stays **flat** (the palette searches it). Entries carry their registered key (`getKey()`), which is how an adopted child stays active on its edit page. Never write `children([...])` with hand-built `route()` URLs for entries that are registered anyway.
- **A section of several screens is a cluster**: `final class Settings extends Clusters\Cluster` (a `Page` — register it in `config('wire-panels.pages')` or discover it; `make:wire-cluster Settings`), and each member names it — `protected static ?string $cluster = Settings::class` on a page (`make:wire-page Taxes --cluster=Settings`), `implements BelongsToCluster` + `cluster(): ?string` on a resource; a nested resource sits where its parent does. Members leave the grouped menu (the cluster's entry stands for them and is lit on each member page; `items()` keeps them), are routed at `{cluster prefix}/{own prefix}` **with unchanged route names**, carry the cluster's `can:`, draw the members beside the content (`$subNavigationPosition`: `Start` default, `End`, `Top`; tabs below `lg`) and start their trail at the cluster. The cluster's address redirects to the first member the viewer may open (403 when all refuse, 404 with no members). A non-cluster, unregistered or nested cluster throws `ClusterException`. Never fake a section with a prefix on each resource plus a hand-written tab row — `ClusterNavigation` is the one owner of membership.
- **`group()` takes a key, not a heading.** `NavigationGroup` (wire-core) owns the heading, icon, order and visibility of a group, because no resource owns the group it shares with others — declare them from a provider with `app(NavigationGroups::class)->registerMany([NavigationGroup::make('sales')->label(__('nav.sales'))->icon(...)->sort(10)])`. **A group nothing declares still works** (an implicit one, heading = `Str::headline($key)`), so registering is for what a bare key cannot say. **Hiding a group hides its entries** — that is the point: the alternative is the same condition on every resource in it. Registering one key twice replaces, so an app can adjust a group a package shipped. Never give a group its own label/icon/visibility properties: `HasName`/`HasLabel`/`HasIcon`/`HasVisibility`/`HasSortOrder` already own those words.
- **One registration seam, three readers.** `Foundation\Registration\Catalog` is everything an application registered, whatever kind it is; a registry joins it by implementing `RegistrySource::registeredClasses()`. `Workspace`, `ResourceRoutes` and `GlobalSearch` all read the catalogue — **never inject `ResourceRegistry` into a new surface**, that is the mistake this replaced: a dashboard used to be listable and neither routable nor findable. Registered ≠ listed ≠ routed ≠ searchable: each surface filters by its own opt-in (`ProvidesNavigation`, `ProvidesPages`, `GloballySearchable`) via `Catalog::implementing()`. Keys are unique across sources and a collision is refused on the first read, not when a menu is drawn. The `pluralLabel()` label fallback is a resource's; anything else names its own entry.
- **`php artisan make:wire-dashboard Sales` writes both halves** — `app/Dashboards/SalesDashboard.php` and
  `app/Livewire/Dashboards/ShowSales.php` — because a dashboard declaring no `pages()` is routed nowhere: an
  entry with no link in the menu and a 404 at every address. wire-panels owns the page generator
  (`make:wire-dashboard-page`, stub publishable with `wire-panels::stubs`); core calls it by name. Register the
  dashboard in `config('wire-core.dashboards')` and `Route::wireResources()` routes it like a resource.
- **`Dashboard` declares widgets, `DashboardPage` renders them** — the same split as `Resource`/`ListPage`, composing the existing `WithWidgets` (grid, visibility, per-widget polling) unchanged. `php artisan make:wire-dashboard Sales` generates one into `app/Dashboards/`; the stub and a `WireDashboardServiceProvider` are both publishable (`wire-core::stubs`, `wire-core::providers`). Register dashboards in `config('wire-core.dashboards')`. A page naming neither a dashboard nor its own `getWidgets()` refuses to render rather than showing an empty grid.
- **`Workspace` groups and orders the menu** and nothing else — `app(Workspace::class)->navigation()` returns `['group-key' => NavigationGroup, …]` with each group carrying `getItems()`, groups in `sort()` order with ties keeping first-appearance, entries the same way inside a group. `items()` is the same menu flat, in `sort()` order, and agrees with `navigation()` about hidden groups. Entries stay **keyed by registered key** through the grouping and the sort, and `Workspace` fills each entry's URL from `ResolvesPageUrls` — `getUrl()` is the page of that key, `null` when nothing routes it, and an entry that called `->url()` itself always wins. The registry still holds no URL; the menu asks for one. No routing, no layout: it reads the registries and never instantiates a resource.
- **A record's other pages draw themselves.** `LinksToRecordPages` (composed by `EditPage`/`ViewPage`, and by a page of your own beside `BelongsToResource` + `ResolvesOneRecord`) answers `subNavigation(mixed $record = null): array<string, NavigationItem>` — the record's pages as tabs, keyed by page kind. **Nothing new is declared for it**: `pages()` is the list and `ResourceRoutes::takesRecord($kind, $page)` reads the *URI* — a `{record}` in it makes the page a tab, whatever it is named, and `RoutePage::make(X)->uri('{record}/history')` is the whole declaration. `RoutePage` carries `label()`/`icon()`/`sort()` from the canonical concerns (**never a fourth vocabulary beside them**); a page that names no label is named by `wire-panels::messages.page_kind.*` or by `Str::headline($kind)`. Three rules decide what is drawn, all three about not drawing something misleading: a page whose `permission()` this user lacks is **left out** (asked through `reachablePageUrl()`, the same ability the route is guarded by), a page whose URL cannot be built is left out, and **fewer than two tabs is none** — one tab is the heading written twice. The current tab is the **page kind**, `Zone::currentPage()`, read at mount into the public `$currentPage` — never a comparison of the tab's URL with the current one, and never re-derived per render.
- **`ProvidesRelationManagers` puts `RelationManager` under the layer without changing it.** `EditPage`/`ViewPage` embed the declared managers against the record; mounting one directly (`@livewire(PostsRelationManager::class, ['ownerRecord' => $user])`) keeps working exactly as before. Declaring none renders none — ordinary, not an error. Note the page passes the resolved record to the view as `ownerRecord`, not `record`: Livewire injects public properties into the view scope, and `$record` there is the *key*.
- **Routes stay the application's.** `Route::wireResources()` / `Route::wireResource(X::class)` register a resource's declared pages **inside the group you call them in** — prefix, middleware and domain remain yours. Anything registered opts in with `ProvidesPages::pages()` (wire-core `Foundation\Routing\` — a dashboard may declare pages too, and is routed by the same macro) (`'index'|'create'|'view'|'edit'`, anything else becomes its own segment); one that declares none is skipped, naming one explicitly that declares none throws. URLs are `{key}`, `{key}/create`, `{key}/{record}`, `{key}/{record}/edit`, named `wire.{key}.{page}`, and `{record}` is a **key** — pages resolve their own record. Per page, `RoutePage::make($c)->permission('x')` becomes Laravel's `can:x` middleware (**never re-implement the check**; Gate answers it, so Spatie/permission-extended keep working); per resource, `ConfiguresRoutes` adds `routeMiddleware()`, `routeDomain()`, `routePrefix()`. **From code, link by class: `OrderResource::url('edit', $order)`, `TaskBoard::url(parameters: [...])`** (`InteractsWithPageUrls`, composed by `DescribesRecords` and `Page`; a model is reduced to its key, unknown parameters become the query string, the zone defaults to `Zone::current()` — pass `zone:` from a kept property inside a Livewire update, and `parameters: ['parent' => …]` for a nested resource). `ResourceRoutes::urlFor($key)` is the same answer by key, null when unrouted — reached from core through `ResolvesPageUrls`, so **never hand-write a path into `toGlobalSearchResult()` or a menu map**; both are filled from the key. **Every package's routes are one kind of thing — a route group (`ProvidesRoutes`, registered in `RouteGroups`) — and no provider registers a route**. The application places a group two ways, same code: `Route::wire('panel', zone: 'admin', tenant: 'path', only: [...])` inside any Laravel group in `routes/web.php` (returns the routes keyed; `wireResources()`/`wireResource()`/`wireZoneEntry()` are the same call), or an entry of `wire-core.routes.groups` (`'admin' => ['uses' => 'panel', 'prefix' => …, 'middleware' => …, 'can' => …, 'routes' => ['orders' => ['can' => …]]]` — every Laravel group attribute, the entry key is the zone), registered by core's one route file **before** `routes/web.php`. The panel starts from `['web', 'auth']`, so an entry naming no middleware is guarded. The same page at two addresses under one name throws; a `RequiresTenant` page is skipped in a group without `{tenant}`. **A panel group's middleware starts from `['web', 'auth']`** — what it registers is a resource's create, edit and delete screens, and a group without `auth` serves all of them to anybody with the URL while nothing looks wrong; a hand-written group owes itself the same middleware. `auth` redirects to the route named `login`, which `nyoncode/wire-module-auth` answers.
- **A tenant zone puts `{tenant}` in the prefix or the domain and adds `wire.tenant`** — by hand, or
  `'tenant' => 'path'|'domain'` on a config zone. The middleware finds the tenant by `wire-core.tenancy.model`'s route
  key, checks `HasTenants::canAccessTenant()` on the user (404 for a stranger, an unknown slug and a guest alike; a
  user model without `HasTenants` throws), enters it in `CurrentTenant`, sets `URL::defaults(['tenant' => …])` so
  every framework link carries it, and forgets the parameter so `mount()` never sees it. It is Livewire persistent
  middleware, so round trips stay scoped and linked. `InteractsWithTenants` implements the contract over
  `members_table`. Never pass `tenant` by hand to `urlFor()`/`X::url()` inside a tenant zone, and never read it in
  `mount()`. The zone's bare address (`{zone}.wire.tenants`) comes with `tenant:`;
  a hand-written tenant zone places it with `Route::wire('tenant-entry', uri: 'app', to: 'app/{tenant}')`: to `getDefaultTenant()`, else `routes.tenant_entry.view` or a 403. The
  company switcher is wire-panels' `PageChrome::TOPBAR` view (`TenantSwitcher`): same page in the other company,
  the resource's list on a record page, nothing for one company.
- **Zones = several mount points, one catalogue.** A zone is the **name** of a route group and nothing else: `Route::name('business.')->prefix('business')->group(fn () => Route::wireResources(only: [...]))` gives `business.wire.orders.index`. A resource may be in one zone, several, or all — what multiplies is the mount point, never the registration. **Membership is `only`/`except`, never a second list.** Every URL question carries the zone: `urlFor($key, zone: 'business')`, `urls(zone: …)`, `Workspace::navigation(zone: …)`; a key that zone does not route answers `null` and renders unlinked — `navigation(zone: …, linkedOnly: true)` drops those instead, which is the menu Filament gets by registering resources per panel, reached here while registering once. **Opt-in, never the default**: `Workspace` cannot tell "routed in another zone" from "routed nowhere", and a shell with its own URL map has everything unlinked and wants it all. An entry of `wire-core.routes.groups` declares them instead, and is safer than a route file because **the array key is the zone** — `->name()` is a line a route file can omit, and omitting it makes one zone silently take over the other's links. **The group's own path always answers**: unless something claims it, `wireResources()` registers `wire.home` (`{zone}.wire.home`) there, which redirects to the first sidebar entry the viewer may open (sidebar order, hidden entries and `can:`-refused routes skipped; 403 when none may be opened, 404 when nothing is registered) and yields to a route the application has at that path, registered before the group or after it — it is what `wire:install` points `fortify.home` at. **A page never takes a path and never loses one**: a page whose path a route already answers is refused, and so is a route registered over a page later in the file (checked once every route is loaded — at boot and at `route:cache`); the same page routed twice over itself passes. **Above several zones, `Route::wireZoneEntry('/')` (`wire.zones`, or a `wire-core.routes.groups` entry that `uses` `zones`) is where signing in should end**: it redirects to the person's `HasPreferredZone::preferredZone()`, else `routes.zone_entry.primary`, else their only reachable zone — each only if its route's `can:` lets them in — and shows `routes.zone_entry.view` (`$zones` key => URL, `$primary`) only for a real choice or on `?choose`. `ZoneDirectory` reads the zones off the router, so hand-written zone groups count too; never keep a second zone list for a switcher. **A zone's landing page is `routePrefix()` returning `ConfiguresRoutes::ROOT`** — an empty prefix adds no segment, so that page's `index` lands on the group's own path; which zone lands where is `only`/`except`, and two pages claiming one group's root is **refused** (Laravel keys routes by URI, so the second replaces the first *and takes its route name*, leaving a menu entry that looks routed and links nowhere). **`Zone::current()` / `Zone::currentKey()` / `Zone::currentPage()` survive a Livewire round trip only on pages `Route::wireResources()` registered** (each carries `RememberPage`, re-run as Livewire persistent middleware into `RenderedPage`); everywhere else they are full-page-render calls: `Route::currentRouteName()` is `livewire.update` on a round trip (and `str_contains($name, 'wire.')` is TRUE for it — the check is anchored, never a substring). Read it in `mount()`, keep it in a public property, pass that — never re-derive it inside a Livewire re-render. A full-page component needs `livewire.component_layout` — the app's, not the package's.
- **`describe-resource`** lists what resources declare — identity, which surfaces each has, navigation entry. Surfaces are reported as declared/not, not by contents: composing them costs exactly what the static half avoids, and `describe-table`/`describe-form` already answer that for the pages.
- **`resolveRecord()` returns `Model|RecordContract|null`.** A page over a read model, a DTO or an API source
  overrides it and returns an `ArrayRecord` or its own `RecordContract`. A **view page** renders that (the
  infolist asks the contract); an **edit page refuses** it with `ResourcePageException::recordIsNotEloquent()`,
  because the form's save lifecycle is Eloquent — override `form()`, leave the model unbound and use
  `Form::using()`. Relation managers are mounted with the **native** record (`nativeRecord()`), since they query
  relations.

## wire-sortable

Adds drag & drop reordering to wire-table via a plugin and `Table` macros. Publish the config and the
`reorderable_column_orders` migration with `php artisan wire-sortable:install`.

### Row reordering

    public function table(Table $table): Table
    {
        return $table
            ->query(Task::query())
            ->reorderable('sort_order')      // enable drag handle, persist to this column
            ->columns([
                TextColumn::make('title'),
            ]);
    }

- `->reorderable($column = null, $condition = true)` enables reordering; `->alwaysReorderable()` keeps it on.
- `->paginatedWhileReordering()` allows reordering across pages.
- `->columnReorderable()` enables column (header) reordering.
- The order column defaults to `wire-sortable.order_column` (`sort_order`).
- The drag handle markup is owned by `Table::getDragHandleHtml()` (a Blade partial), not hand-built JS.

These are `Table` macros registered by the sortable service provider, so they are only available when
wire-sortable is installed.

### JavaScript assets

`wireSortable` ships as a package bundle (`dist/wire-sortable.js`) with **SortableJS compiled in**,
declared as a toolkit asset entry with the `wire-sortable.asset` route behind it — so reordering
needs no npm install, no `vendor:publish` and no CDN request, and works offline and under a strict CSP.
`resources/views/partials/scripts.blade.php` is now only a thin `@assets` wrapper (script tag + the
`.wire-sortable-*` drag CSS); the Alpine component is not in the Blade any more.

`config('wire-sortable.sortablejs_cdn')` **defaults to `null`** and can no longer affect reordering:
when set, its tag is still emitted, but the controller closes over the bundled import and never reads
`window.Sortable`. Migration note for a consuming app: if the app's *own* JavaScript relied on that CDN
script leaving a global behind, it must now set the key or bundle SortableJS itself.

As with every wireStack package, put `@wireStackScripts` in the layout `<head>` so the bundle is in the
initial document; the view's own `@include` remains a fallback.

### Boards (kanban)

- **`Board` declares, `WithBoard` hosts.** `Board::make()->model(Task::class)->groupBy('status')->lanes(TaskStatus::class)->cardTitle('title')->orderColumn('position')`; lanes are `Lane`s, `value => label`, or a backed enum (its core `HasLabel`/`HasColor` label and colour the lanes). A board page is a wire-panels `Page` that composes `WithBoard` with `protected static string $view = 'wire-sortable::board.content'` — neither package depends on the other, so never add a `BoardPage` class to either.
- **The drag is Livewire's `wire:sort`** (one group across the lanes, the lane as group id) — no script of this package. A drop calls `moveBoardCard($key, $position, $lane)`: unknown lane or a record outside the board's `query()` is ignored, `canMoveBoardCard(Model, string)` may refuse, then `MoveBoardCard` writes the lane and renumbers the lane in one transaction (only when `orderColumn()` is set). Override `boardCardMoved()` to react — never write the lane column yourself from the view.

## wire-suite

A meta-package plus one interactive command. `composer require nyoncode/wire-suite` brings core, forms,
tables, sortable, resources and the admin shell in one line; `php artisan wire:install` sets them up by
running **each package's own installer**, never a copy of it.

- **The installer never runs composer.** It lists what it found in the application, sets that up, and then
  prints the `composer require` lines for the modules that are *not* installed. An artisan command that
  shells out to composer runs inside the application composer is rewriting the autoloader of; the failures
  that follows cannot be debugged from a stack trace. So: when a part is missing, hand over the line to
  paste — do not add a shell-out.
- **Everything is offered pre-selected**, because an installer whose default is "nothing" makes the common
  case the tedious one. `--all` skips the question, `--dry-run` shows the plan without doing any of it, and
  `--no-interaction` is passed down to the installers it runs.
- **Two questions, not one.** A catalogue `Component` carries a `ComponentGroup`: `Stack` is offered to
  everyone, `Module` only once `wire-admin` is part of the answer (a ready-made area renders inside the
  shell), and `Tooling` (`wire-boost`) is listed and never run. A new module entry in `Install\Catalogue`
  must say `group: ComponentGroup::Module`, or it lands in the first question.
- **It only runs what has something left to do.** `Install\Setup` reads each part's declared publish
  groups and skips the part when every destination is already there — `--force` overrides it. This is
  correctness, not speed: a migration shipped without a date prefix is stamped when the publish mapping is
  built, so re-publishing writes a *second* copy of one the application already has and `migrate` then
  fails. Never re-run a package installer to "make sure"; check first or pass `--force`.
- **The second half is setup, not installation.** `wire:install` runs the package installers and then
  works through `SetupStep`s: Fortify's config and features, the user model (`TwoFactorAuthenticatable`,
  `PasskeyAuthenticatable` + `PasskeyUser` for the features switched on — written with
  `Foundation\Setup\ClassSource`, the one helper for editing an application class), teams, roles,
  migrations, routes, the
  sign-in destination (Fortify's default `home` of `/home` pointed at the admin's root, which only the
  suite can do — it knows both Fortify's file and the panel prefix; an application's own `home` is left
  alone), the first administrator, `storage:link`, audit recording, stored notifications, the settings cache, the frontend
  build. A package contributes one
  with `SetupRegistry::instance()->register(...)` from its `registeredPackage()` hook — **inside the
  one it already has**, because the toolkit's lifecycle hooks assign rather than append and a second
  call drops the first. The contract is `WireCore\Foundation\Setup\Contracts\SetupStep`:
  `state()` may only look (it runs under `--dry-run`), `Blocked` is how a step says "not yet, and
  here is why" instead of being offered, and `apply()` asks through `SetupConsole` (`confirm`, `ask`,
  `choose`, and `select` for "which of these", which returns its default unattended) so the step is
  testable without a terminal. `package()` names the composer package the step belongs to: when that
  package was offered and unticked, its steps do not run; a package outside the catalogue always runs
  (that is how `RunMigrations` says "always", as `nyoncode/wire-suite`). **Never put step logic in
  wire-suite** — it belongs to the package that knows what a media disk or a super-admin role is.
- **A step wraps another package's installer rather than copying it** — `ConfigureFortify` calls
  `fortify:install`, `EnableRoles` calls `permission-extended:install` — and checks the command is
  registered (`Kernel::all()`), not that a class autoloads. A step that must run before `migrate`
  (it publishes a migration, or sets a switch a migration reads) sorts below `RunMigrations` — and
  below any step whose wrapped installer migrates on its own (`permission-extended:install` does,
  which is why teams sorts before roles). A switch a migration reads in this run is set with
  `config()->set()` as well as in the file.
- **Ask only through `SetupConsole`, never a Prompts function in a step.** `Artisan::call()` leaves
  Laravel Prompts' static output and interactivity as the called command set them — a buffer, or
  "nobody is here" after `--no-interaction` — and `CommandConsole` is what restores both before each
  question. **A file changed on disk is not the class this process loaded**: after
  `permission-extended:install` patches the user model, `Roles::available()` still says no until a new
  process; work that needs the patched class runs in one (`CreateFirstAdministrator` makes the super-admin via
  `wire:assign-role --super-admin` under `Process`).
- **A third-party config literal is edited only through `Foundation\Setup\ConfigFile`**, and only after
  `EnvFile` has no home for it. `set()` rewrites one single-line `'key' => value,` that occurs exactly once
  and returns false otherwise; never add a key or fall back to a wider regex — tell the user the line.
- **A failed installer fails the command.** `Artisan::call()`'s exit code is the run's exit code, and the
  parts that failed are named. Do not drop it.
- **Requiring the suite is not adopting the shell.** `wire-admin` still only renders a page once the
  application's own layout view says so, and naming the layout is a second, separate opt-in.
- Nothing in the stack requires `wire-suite`. It is a convenience for installing, never something to
  depend on from a package or to check for at runtime — ask `application-info` which packages are actually
  present instead.

## wire-table

Build a data table inside a Livewire component using the `WithTable` trait and a `table()` method:

    use NyonCode\WireTable\Concerns\WithTable;
    use NyonCode\WireTable\Table;

    class Users extends Component
    {
        use WithTable;

        public function table(Table $table): Table
        {
            return $table
                ->query(User::query())
                ->columns([
                    TextColumn::make('name')->sortable()->searchable(),
                    BadgeColumn::make('status')->colorUsing(fn ($state) => $state === 'active' ? 'success' : 'gray'),
                    BooleanColumn::make('is_admin'),
                ])
                ->filters([
                    SelectFilter::make('status')->options(Status::class),
                ])
                ->actions([EditAction::make(), DeleteAction::make()])
                ->headerActions([HeaderAction::make('create')])
                ->bulkActions([DeleteBulkAction::make()]);
        }
    }

### Data sources

A table reads rows through a `DataSource`. `->model()` / `->query()` build an
`EloquentDataSource` behind the scenes and nothing about them changed; the seam
matters when the rows are not in a database.

**`->dataSource(new CollectionDataSource([...]))` gives a table with no model and
no builder.** Search, filters, sorting and paging all work — the source answers
them over the array, because `QueryPlan` states a filter as a column, an operator
and a value rather than a closure. Footer summaries and "select all matching"
work over it too. Anything written as an Eloquent callback — `DateFilter`,
`Filter::query(fn …)`, a search or sort callback — throws
`CustomDataSourceException` the moment it is used; give such a table plain
Select/Text/NumberRange filters instead.

**A source declares what it can do, and an undeclared aspect throws.** That is
the safety property, not a limitation to route around: a table that sorts by
nothing and looks sorted is worse than one that errors. `CollectionDataSource`
declines raw SQL expressions, relation paths, subquery aggregates and cursor
paging, and returns `null` from `changeToken()` so polling compares rows instead.

**User code still receives models.** The framework unwraps `RecordContract` at
the boundary, so `->action(fn (Model $record) => …)` is unchanged — including on
standalone actions. Over `CollectionDataSource` the row arrives as a read-only
`CollectionRow` model built from the array: `$record->name` reads, `save()` and
`delete()` throw.

**Two paths stay Eloquent-only**, because the contract cannot express them:
selection rollups replay aggregate subqueries, and the fill handle's writes take
a pessimistic row lock.

Full page: `docs/table/data-sources.md`.

### Columns

`TextColumn`, `BadgeColumn`, `BooleanColumn`, `IconColumn`, `ImageColumn`, `ButtonColumn`, `ToggleColumn`,
`CheckboxColumn`, `PollColumn`, `SelectColumn`, `TextInputColumn`, `SplitColumn`, `StackedColumn`,
`ColorColumn`, `RatingColumn`, `TagsColumn`.

A cell no fluent method draws is a column class: `php artisan make:wire-column Price` writes
`app/Tables/Columns/PriceColumn.php` (overrides `renderCell()`, view name set on `$view`) and
`resources/views/tables/columns/price.blade.php` (receives `$column`, `$record`, `$state`, `$value`).
The view is never overwritten; `--force` replaces the class. Stubs: `vendor:publish --tag=wire-table::stubs`.

`BadgeColumn` (and `IconColumn`) color/icon resolution — pick by intent:
- one fixed color for every row: `->color('success')` (takes `string|Color|null`, never a Closure);
- a static state → color map: `->colors(['active' => 'success', 'draft' => 'gray'])`;
- a value computed per row: `->colorUsing(fn ($state) => …)` (the Closure receives the cell state);
- nothing at all when the state is an enum implementing `HasColor` — the color resolves automatically.
The same four-way choice applies to icons: `->icon()`, `->icons([...])`, `->iconUsing(fn ($state) => …)`, or an enum with `HasIcon`.

Dot-notation relation columns (`TextColumn::make('company.name')`) resolve by mechanism, not one JOIN for
everything. **Display** always eager-loads the relation (`with()`) — every relation type, including nested chains
like `company.country.name` — so a shown relation value never triggers an N+1 and never depends on a join.
**Sorting** by a singular relation (`belongsTo`/`hasOne`/`hasOneThrough`) uses a real `LEFT JOIN`; the joined
side is a scoped subquery that honours the related model's global scopes and any `->where()` on the relation.
**Filtering** by a relation column uses Eloquent's native `whereHas()` (an `EXISTS` subquery), so it works for
any relation type — including `hasMany`/`belongsToMany` — and honours the relation's keys, scopes, and
constraints automatically.

### Filters

A custom filter class that overrides `apply()` must also return `true` from `bypassesPlanner()`: a single scalar
value otherwise goes to the `QueryPlanner` as `column = value` and `apply()` never runs. `php artisan make:wire-filter Region`
writes both (`app/Tables/Filters/RegionFilter.php` + `resources/views/tables/filters/region.blade.php`, rendered by name so a
built-in `wire-table::tables.filters.*` view never answers for it).

`SelectFilter`, `DateFilter`, `NumberRangeFilter`, `TernaryFilter`, `TrashedFilter`. A filter query callback must return the
Builder. It receives the value already normalized for its filter type — a `TernaryFilter` callback gets a real
`bool`, never the `'true'`/`'false'` option key, so branch with `$value ? … : …` and never compare to a
string. Use `->indicator()` for filter chips and `->subRows()` to scope sub-row filtering. `TrashedFilter` constrains no
column — it switches the soft-delete scope (`'with'` → `withTrashed()`, `'only'` → `onlyTrashed()`, cleared → live
records) and requires the model to use `SoftDeletes`. It also makes the table find a trashed row **by its key** when that row's action is
clicked and keep a ticked trashed row in a keyed selection, so a *Restore* row action works; a table without it
keeps the soft-delete scope on every lookup.

Filtering by a relation aggregate uses the `orders->count()` / `orders->exists()` path syntax
(`Filter::make('orders->count()')`). It is applied as a `WHERE` over the aggregate subquery via Eloquent's
native `whereHas($relation, null, $operator, $count)` / `whereDoesntHave` — **never `HAVING`**, which
PostgreSQL rejects without a `GROUP BY`. `sum`/`avg`/`min`/`max` aggregate filters have no native primitive
and are not applied (skipped, not errored).

Per-column header filters are a **placement of the same canonical `Filter`** in the header cell (not a
separate engine): `->filterable()` (text, with `->filterOperator()`), `->filterAsSelect()` (single),
`->filterAsMultiSelect()` (several values → `whereIn`), `->filterAsBoolean()`, `->filterAsDate()`,
`->filterAsDateRange()`, `->filterAsNumberRange()` — thin factories over `TextFilter` / `SelectFilter` /
`DateFilter` / `NumberRangeFilter` / `TernaryFilter`. Or pass a ready filter with `->filter(SelectFilter::make(...))`.
Options accept an array or enum class. `filterAsSelect`/`filterAsMultiSelect` render the **canonical
searchable combobox** (the same `searchable-select` used by wire-forms `Select` and the table `SelectFilter`)
— search is on by default; `->filterSearchable(false)` drops it. All controls share one style owner
(`Support\FilterControl`) that mirrors the wire-forms field look. They write to the `columnFilters` state
(separate from table `filters`), are planned through the same `QueryPlanner` as panel filters (date/boolean
fall back to `Filter::apply()`), and inherit authorization, **indicator chips** (removable, alongside panel
chips), and **query-string persistence** (`Table::queryString()`, under a `col_<column>` URL parameter).

### Search syntax

`Table::searchable()` splits the term into words **by default** (`tokenize()` is on): every word must
match, each in any searchable column (`LIKE`/`ILIKE`), a "quoted phrase" stays one word, and a `%` or
`_` the user types is escaped rather than acting as a wildcard. Case and accents follow the DB
collation (`utf8mb4_unicode_ci`: `novak` finds `Novák`); a `CollectionDataSource` folds both sides
itself (`Core\Query\Search\SearchText`). `->search(fn ($s) => $s->literal())` restores one substring
of the whole term. Ranges and wildcards stay **opt-in per table** through `Table::search()`:

```php
use NyonCode\WireCore\Core\Query\Search\SearchConfig;

$table->search(fn (SearchConfig $s) => $s
    ->ranges()      // >100, >=100, <10, <=10, =42, 10..20, 10.., ..20, 2026-01-01..2026-03-31
    ->wildcards()   // nov* / a?b
);
```

Structured codes (`8866 01`, `8866 02` — shared series, zero-padded tail) get `Column::searchAs('code')`:
typing `8866 01..08` becomes one `BETWEEN '8866 01' AND '8866 08'`. The space inside the code also
splits the term, so the range carries the word typed before it and a code column completes both
bounds with it (write the series once); any other column ignores that word and reads `01..08` as the
plain range, so `praha 10..20` still works on the same table. The number must be stored padded and typed as stored
(`1..8` against stored `01 … 08` compares at the typed width and misses the padded series); a range
crossing a width boundary is completed — `8866 50..100` reads as `050..100`. `searchAs()` switches
nothing on by itself: a searchable column declaring a type while the table's search does not read
ranges is refused when the table renders, naming the missing `->search(...)` call.

**Every other search box reads a term the same way — never hand-write `where($col, 'like', "%{$term}%")`.**
A search over a builder and a few columns goes through `Core\Query\Search\WordSearch`
(`app(WordSearch::class)->apply($query, ['name', 'city'], $term)` — the palette, `BelongsToSelect`,
the media and notification screens use it); a list filtered in PHP through `SearchText::matches()`;
a list filtered in the browser through `searchMatcher(term)` from `core/resources/js/support/search.js`
(select options, `CheckboxList`, tag suggestions, the admin menu filter).

`tokenize()` is what makes a first name in one column and a surname in another match together.
`ranges()` only asks a column that can answer — the value type comes from the model's casts, or
from `Column::searchAs('numeric'|'date')` where a cast cannot speak for the column; a comparison
no column can answer is searched as the literal text typed, never as an empty group that matches
everything. A typed date means its whole span (`2026-01-31` the day, `2026-01` the month, `2026`
the year). `Column::searchable(['first_name', 'last_name'])` searches exactly the columns listed;
`Column::searchUsing(fn (Builder $q, string $term) => ...)` OR-combines with the planned columns
and receives one word at a time (the whole term only under `literal()`).

### Relation managers

A relationship-scoped table as a standalone Livewire component. Extend `RelationManagers\RelationManager`,
set `protected string $relationship` (and optional `protected ?string $title`), and define `table()` exactly
as in any `WithTable` component — columns, filters, actions, exports, search and sorting all work. The base
class pins `query()` to the owner record's relationship, so a subclass cannot widen it. Render with
`@livewire(PostsRelationManager::class, ['ownerRecord' => $author])`.

Any relationship type can be listed; for belongs-to-many the query selects `related.*` so pivot columns
cannot overwrite related attributes or the row key. Create/attach/detach actions call the base helpers —
`$this->createRelatedRecord([...])` (sets the FK; creates + attaches for belongs-to-many),
`$this->attachRelated($id, [...pivot])` and `$this->detachRelated($id)` (belongs-to-many only, `null`
detaches all). Using one against an unsupported relationship type throws a clear `RuntimeException`.

### A table inside a dashboard card

`Widgets\TableWidget` is this package's widget: a few read-only rows of a table in a dashboard card — no
toolbar, no filters, no pagination, no bulk actions. `TableWidget::make()->heading('Recent orders')->limit(5)
->table(fn (Table $table): Table => $table->query(Order::query()->latest())->columns([...]))`. It lives here
rather than in wire-core, which owns every other widget kind, because the engine that renders the rows is
here; before 2.0 it was core's and rendered an empty div, since from there the table could not be reached.

### Gesture layer

The desktop behaviour — keyboard grid navigation, Shift/mod ranges, the drag sweep, the right-click row
menu, the `?` help and the Excel fill handle — is one layer, owned by `Support\TableGestures` and
configured with `Table::gestures()`. **It is opt-in.** Keyboard navigation, range selection and the drag
sweep are OFF for a table that never calls `gestures()`, because each changes how the table answers a
visitor who never meant to operate it (rows enter the tab order, an active row is marked, a press in the
checkbox column starts selecting a block, a Shift+click stops meaning a click). A selectable table starts
as checkboxes and nothing more and mounts no delegated controller at all. Right for a back office, wrong
for a public listing:

    ->gestures()                                                 // the desktop-app table
    ->gestures(false)                                            // not even the quiet capabilities
    ->gestures(fn (TableGestures $g) => $g
        ->keyboard()                                             // arrows, Enter, shortcuts …
        ->dragSelect(false))                                     // … but still no mouse sweep
    ->gestures(TableGestures::none()->contextMenu())             // a prepared set, shared across tables

Six capabilities, each its own setter and `allows*()` reader, with the default a table gets without asking:

| Capability | Default | Covers |
|---|---|---|
| `keyboard` | **off** | roving tabindex, arrows, Home/End, PageUp/PageDown, Enter / Shift+Enter, Space, every `keyboardShortcut()`/`onKey()` against the active row — and what makes the table an ARIA `grid` |
| `rangeSelection` | **off** | Shift / mod / mod+Shift click, Shift+arrow, Shift+Home/End |
| `dragSelect` | **off** | the checkbox-column sweep |
| `contextMenu` | on | any `onContextMenu()` record-action binding |
| `shortcutHelp` | on¹ | `?` |
| `fillHandle` | on² | the Excel-style handle on editable cells |

¹ reads the keyboard layer, so with the default it never opens. ² still needs `Table::fillHandle()`.

The three that stay allowed already need an invitation of their own — actions bound to the context menu,
`fillHandle()`, and (for the `?` help) the keyboard layer this default leaves off — so with the shipped
default a table offers only what it declared itself. The closure receives
this table's gestures and configures them **in place**; its return value is ignored, so a fluent chain and
a multi-line body both work. `TableGestures::defaults()` / `all()` / `none()` are the three starting
points: shipped default, everything, nothing.

Readers, all on `Table` and all consulted rather than re-derived: `usesGridSemantics()` (the single owner
of "is this an ARIA grid"), `keyboardNavEnabled()`, `usesRangeSelection()`, `usesDragSelect()`,
`usesShortcutHelp()`, `usesActiveRowMarker()`, `mountsRecordActionController()`, `getGestureConfig()`
(the `{sweep, ranges}` the client controller consumes), `getRecordActionKeyboardConfig()`, `getTableRole()`,
`hasRowContextMenu()`, `isFillHandleEnabled()`, `getGestures()` (the raw permissions).

**A capability is a permission, never a trigger.** Switching one on never conjures the thing it governs:
a sweep still needs `selectable()`, ranges still need a selection to grow in, `fillHandle` still needs
`Table::fillHandle()` plus editable columns, and `shortcutHelp` cannot outlive the keyboard layer that
listens for the key. `keyboard` is the one **three-state** switch (`false` = the shipped default; `null` = the table decides,
which it takes for record actions or a selectable table — this is what `gestures()` sets, deliberately
NOT `true`, since a table with neither has nothing for the arrows to do; `true` = force it on regardless);
the other five are plain booleans.

**What the layer does not govern: an explicitly declared record action.** `RecordAction::make('view')->onClick()`
is a deliberate statement about that table, not an affordance the table turned on for itself, so it keeps
firing with `gestures(false)`. The exception is `->onKey()`, which needs the keyboard layer to listen with.
Selection is likewise untouched — checkboxes, both select-all controls and the bulk bar work with every
gesture off; you lose the shortcuts to them, not the feature.

**It is off on the server, not just ignored on the client.** The delegated Alpine controllers are not
rendered (a table with nothing but the gestures off renders no controller at all and requests no bundle),
`role="grid"`/`role="row"`/roving tabindex go away, rows stop being focusable so a click steals no focus,
the `fillTableCells` endpoint **refuses**, and `shortcutLegend()` drops the rows that no longer apply —
with ranges off, Shift+arrow is not listed in the `?` help because it does not work. The legend is
generated from what the table actually does, so it cannot drift from reality.

The project-wide default is `config('wire-table.defaults.gestures')` — `null`/absent keeps the shipped
default above, `true` turns the whole layer on for **every** table (what a back-office project sets once),
`false` allows nothing, and a map (`['keyboard' => true, 'drag_select' => false]`) mixes on top of the
shipped default; keys match loosely (`drag_select` = `drag-select` = `dragSelect`), and an **unknown key
throws** `TableConfigurationException` rather than quietly doing nothing. A per-table `gestures()` always
wins. Consequence for fixtures and tests: anything asserting `role="grid"`, a roving tabindex, arrow
navigation, a Shift+click range, a sweep or the `?` help must call `->gestures()` first.

The active-row marker appears only when something needs an anchor to grow from — grid semantics, range
selection or the sweep (`usesActiveRowMarker()`). A table left with nothing but a declared click action
marks nothing: the click opens the record and moves on, and a highlight left behind would be an
application affordance on a page that asked for none.

**Phones get buttons instead of gestures.** There is no double click, no right click and no hover to
discover either, so a behaviour-only record action would be *unreachable* on a stacked mobile card — it is
therefore rendered as an ordinary button there, and only there (`getMobileRowActionsForDisplay()` vs
`getRowActionsForDisplay()`). The fallback never doubles anything: row actions keep their order with the
record actions appended after them, a `recordAction('edit')` that only *references* an action already in
`->actions()` yields one button, an action promoted with `->alsoInRowActions()` is already a button and is
left alone, and the fallback buttons count towards `->collapseActionsOnMobile()`. Turn it off with
`->recordActionButtonsOnMobile(false)`.

**Tablets keep the gestures, with a finger.** A tablet gets the desktop table, not the card, so the row's
`wireRecordActions` controller maps them: tap = click, double tap = double click (counted in JS — Safari's
dblclick is unreliable), long press = right click. The menu a finger opens is the right-click menu plus
`Table::getTouchMenuActions()` — the same fallback list, minus what the menu or the actions column already
holds — and a table with an actions column shows a `⋯` (`data-testid="row-touch-menu"`) there on
`(pointer: coarse)` only — whenever the row has a menu at all (`Table::hasTouchGestures()`), since a
right-click menu with no touch items is still unreachable with a finger. Nothing to declare; it follows `recordActionButtonsOnMobile()` and the
`contextMenu` gesture switch. Do not add touch buttons or a second menu of your own for this.

The card renders a **copy** with the keyboard shortcut stripped (`HasKeyboardShortcut::withoutKeyboardShortcut()`,
new in wire-core). A rendered action button binds its `keyboardShortcut()` as a **window** listener
(`x-on:keydown.{key}.window` in `wire-core::actions.button`) and the stacked cards are in the document at
every width — so without this one `Delete` press ran an `onKey('Delete')` action once per card behind the
desktop table. General rule: **never render the same shortcut-carrying action on two surfaces.**

### JavaScript assets

The table's Alpine controllers (`wireRecordSelection`, `wireRecordActions`, `wireFillHandle`,
plus core's `wireDropdown`/`wireContextMenu`/`wireEditableCell`) ship as pre-built bundles
served from each package's own asset route — nothing to publish, nothing to build.

**The primary delivery path is `@wireStackScripts` in the app's layout `<head>`.** The
per-surface `@include`s of `partials/selection-assets.blade.php` & co. are now a **fallback**:
they still work, and they dedupe against the directive, but tell an app to add the directive.
Only a bundle already in the initial document survives Livewire's cached Back/Forward path,
which does not await newly injected head scripts — that is what `wireRecordSelection is not
defined` after a `wire:navigate` (dead dropdowns, a grey sheet-backdrop scrim over the table)
comes from. `@wireStackScripts('wire-table')` narrows it to one package.

Registration rules live in the wire-core guidelines: register unconditionally, never only from
`alpine:init`; lazy the heavy bodies, never the registrators.

### More

- Summaries: per-column `->summarize(...)` with footer scope toggles; grand totals computed in SQL. A column's `numeric()` / `money()` formats its totals too, so do not repeat it as `summaryDecimals()` — reach for that only when the total should read differently from the cells.
- **Resources are not this package.** An entity's owner layer lives in `wire-core` (identity + `ResourceRegistry`) and `wire-panels` (surfaces + pages); a table is *given* to a resource, it does not own one. `packages/table/tests/Unit/Architecture/TableOwnsTablesTest.php` enforces that — no owner-layer namespace, no owner-layer import, no page view. See the wire-panels guidelines.
- Sub-rows: expandable child records via `->subRows('relation')` + `->subRowColumns([...])`, with per-parent subtotals, `->subRowsLimit()` ("show more"), and an interactive filter bar (`->subRowsFilterable()`, filters the **children**). Expansion is one baseline, not a per-row list: `->subRowsDefaultExpanded()` sets where rows start, the master chevron in the expander column header (or `toggleAllRowExpansion()`) moves it, and it survives pagination + is stored per user with `rememberColumns()`. `flattenSubRows()`/`toggleFlattenMode()` were removed in 2.0 — they never flattened anything, they were a second name for this baseline. `subRows()` is table-wide, so **every** row gets a chevron unless `->subRowsVisible(fn ($record) => ...)` says which records can have children at all — rejected records lose the chevron, the panel and their share of the eager load, but keep an empty expander cell so the columns stay aligned (the result is memoized per record, so the callback may query — prefer a `withCount` attribute). It is not "has none right now" — that case is `->subRowsHideWhenEmpty()`, which is **not** the same closure written by hand: it makes the table's own query carry a constrained presence count (its own alias, so a rollup count column keeps `{relation}_count`), so the per-row check is an attribute read rather than a `COUNT` per row. That count honours `subRowQuery()` and `Filter::subRows()` but deliberately **not** the interactive `subRowsFilterable()` bar, whose values change per parent. Both conditions compose, cheap one first.
- **A large selection is a query, not a `Collection`.** Besides the keyed selection, the user can "select all matching the filter" (`selectAllMatchingRecords()` / the bulk-bar escalation), stored as a mode whose list holds the *exclusions* — a filter/search change drops it back to explicit keys. A bulk-action callback still receives a `Collection`, but `Table::bulkMaxRecords()` (default 1000) caps what one action loads and the action **refuses out loud** past it. For an action that must handle any size, walk it: `->eachSelectedRecord(fn (Model $r) => ..., chunk: 500)` or `selectedRecordsQuery()` — never expand it into keys.
- Grouping with subtotals, and exports (`withSummaries`).
- **A queued export is a different delivery, not the same one moved.** A download is a `StreamedResponse` and a job has none to return, so every exporter writes through `Exporter::writeTo(string $path, …)` and `export()` is that method inside a response wrapper — `php://output` is a path like any other, which is what keeps a download and a stored file the same rows, columns and summaries (a custom exporter must implement `writeTo()`). `$this->queueTableExport(format, disk, directory)` dispatches `RunExportJob`, which carries a **component class and the table state**, never a query: a query is closures and a builder that do not survive serialization, so the host is rebuilt and re-asked — and without the state travelling with it the worker mounts fresh, so a user who filtered to twenty rows would receive all ten thousand. The file lands via `TableExport::store()` (temp file → `Storage::disk()->put()`, so S3 works) and the user is told where by notification, because a large export finishes with no request left to flash into.
- **A queued import needed nothing new in the import pipeline** — `TableImport::import(string $path)` was already path-in, result-out. `$this->queueTableImport($path, $disk)` takes a **disk path, not the temp upload's real path**: store the upload first (`$file->store('imports')`) and pass what that returns, because the worker may be another machine and `CsvImporter` opens with `fopen()`, which does not speak S3 — the job pulls the file down to a temp path itself and deletes it after. Counts arrive as a notification (**warning**, not success, when rows were rejected), since a queued run has no return value. A **missing file throws** `ImportException::fileNotFound()` rather than importing nothing: the CSV reader treats an unreadable path as "no rows", which is right when the user is watching and a lie on a queue, where "imported 0 row(s), 0 failed" is indistinguishable from an empty file.
- Inline editing via `TextInputColumn` / `ToggleColumn` / `SelectColumn`. All three share one canonical Alpine component (`wireEditableCell`): the cell updates **optimistically**, rolls back on failure, and carries the row version for **optimistic-lock** conflict detection (conflict shown inline on the cell; opt-in toast via `Table::notifyEditConflicts()`). The version is `RecordVersion` — the model's own `updated_at` column, so `const UPDATED_AT` is honoured; do not hand-roll the stamp, a literal `->updated_at` read renders the `'0'` sentinel for such a model and `conflicts()` reads `'0'` as "the client never had a version", leaving every edit on it unguarded. Edits the **same request** made are not a conflict: Livewire bundles calls issued in one tick into one request, so tabbing out of one cell straight into another on the same row sends both holding the version from their shared render — `RecordVersion` remembers the stamp each record carried when the request opened and accepts it however many times that request has written since, while a version matching neither the current stamp nor that baseline is still refused. The save (`updateTableCell`) **renders**, and the cell reconciles from a sync node rather than being reset by the morph (see gotchas). Server-side `canEdit(Model $record)` enforces per-record `disabled()`/permission — client `disabled()` is cosmetic only.
- **Fill (Excel-style), server side.** `Table::fillHandle()` opts a table in to writing one value across many rows in **one** request (`fillTableCells`); `Column::fillable(false)` excludes a column that is otherwise editable (a unique code, an invoice number), and `Table::fillMaxRecords(int)` caps a single request (default 500). Each record still goes through the full per-record path — `canEdit()`, its own rules, its own optimistic-lock version — so a fill is deliberately **not** all-or-nothing: one row losing its race is reported as a per-record failure while the rest land. Records are resolved through the table's own query, so a key outside it is never written. The endpoint refuses outright unless `fillHandle()` is on. Per-cell `CellUpdating`/`CellUpdated` fire exactly as for a single edit — there is no separate bulk event. The payload is a **list** of `{column, value, records}` entries where `records` maps record key to the optimistic-lock version the client holds (a map, not a bare list of keys — PHP casts a numeric string array key to an int, so `{"15": "…"}` and `["…"]` would be indistinguishable). Driving `fillTableCells` repeatedly means sending the versions the previous call **returned**, never the ones you started with — that is what a later request needs, and a fill in a request of its own is refused without it. (Two fills bundled into the *same* request are forgiven by the baseline rule above; do not rely on it, since which calls share a request is Livewire's decision, not yours.) The version is `updated_at` to the second, so two writes inside one second are indistinguishable and a stale version is not caught there.
- Conditional row styling: `Table::rowColor(string|Closure|null)` tints a whole row with a semantic/hue color resolved by the canonical `HasColor` owner (return `null` from the Closure for no tint; a tinted row gets a same-hue hover and drops the neutral hover/striping). `Table::rowClass(string|Closure|null)` adds arbitrary classes (the Closure receives the record). Prefer `rowColor()` over hand-written `bg-*` classes; combine both for e.g. a danger tint + `font-semibold`.
- **Inactive records (a row that stays and stops being writable).** `Table::rowInactive(bool|Closure $when = true, Closure|InactiveRow|null $configure = null)` marks records inactive — a cancelled order, a voided line, an archived customer: still listed, searched and counted, no longer editable. The second argument configures `Support\InactiveRow` **in place** (the `gestures()` pattern): `->strikethrough()` (off by default — it claims the *value* was voided), `->dim()` (on by default), `->color('danger')` (feeds the same canonical row tint `rowColor()` uses; an explicit `rowColor()` wins over it), `->editing(true)`, `->selectable(false)`, `->actions(false)`. **The lock is server-side and reuses the existing gate**: the table pushes its rule into every editable column's per-record disabled state through `Contracts\InheritsRecordState`, so `Column::canEdit()` refuses the write in `CellEditPipeline::commit()` — a forged request and a fill-handle drag included — and a column's own `disabled()` still applies alongside (either one disables the cell, in any declaration order). The row carries `aria-disabled` + `data-inactive`, the strike names the row's inputs explicitly (`<input>` does not inherit `text-decoration`), and the stacked card gets the same state. `selectable(false)` makes the checkbox `inert`, skips the row in "select page" and refuses a forged toggle — but a **"select all matching"** selection is a query, so a PHP predicate cannot narrow it and a bulk action must check the record itself. `actions(false)` makes the action cell `inert`, skips the context menu and refuses `executeTableAction()`/`openActionModal()` — a *bound* record action (`onClick()`/`onDoubleClick()`) included, since it runs through the same two methods; only `recordUrl()` (a plain link) is untouched. Project-wide defaults: `config('wire-table.defaults.inactive_rows')`.
- Per-user column memory: `Table::rememberColumns('key')` loads each user's saved hidden-column set on mount and persists it on every toggle, scoped to `auth()->user()` (one key serves all users; stale column names are ignored). Storage is a driver chosen in `config('wire-table.preferences')` — `null` (default, no persistence), `session`, or `database` (publish `wire-core::migrations` → `wire_preferences` table). The store itself lives in wire-core (`Foundation\Preferences`) because a dashboard layout uses the same one. `Table::preferenceDriver($driver)` overrides per table; a "Reset columns" control clears the saved layout. Implement `Foundation\Preferences\Contracts\PreferenceDriver` for a custom store.
- **Record actions (whole-row interaction), a distinct group from `->actions()`/`->bulkActions()`/`->headerActions()`.** `Table::recordActions([...])` / `recordAction(string|Action|RecordAction)` bind an action to a row gesture: `Action::make('edit')->onDoubleClick()` (also `->onClick()`, `->onContextMenu()`, `->onKey('Delete')`, `->on('custom')`). Those fluent triggers are `Action` macros that **return a `RecordAction`** (a table-owned wrapper — the shared `Action` class stays clean); it belongs in `recordActions()`, and `->actions()` rejects it out loud. A bare name (`recordAction('edit')`) references an action already in `->actions()`. Execution reuses `openActionModal`/`executeTableAction` (auth, confirmation, forms unchanged) — no second pipeline. **Behaviour-only by default** (no button — this is what makes a table feel like an app); `->alsoInRowActions()` also renders it in the column, `->behaviorOnly()` states the default. **One delegated Alpine controller (`wireRecordActions`) on the `<tbody>`** — never per-row — resolves the row from `data-row-key` and ignores clicks on any interactive element inside the row (buttons/checkboxes/links/editable cells/dropdowns) with no `stopPropagation()` needed. `onContextMenu()` feeds the row context menu (a single delegated menu, positioned at the cursor; closes on outside-click/Escape/scroll). When selectable, the default trigger is **double-click**, leaving the single click free for selection work — a *plain* click only marks the row (active row + range anchor) and never ticks the checkbox, while a **modified** click is a selection gesture and never runs a bound action (see the selection-gestures bullet). Keyboard nav needs `gestures()` **and** a table the keyboard can drive row by row — record actions **or** `selectable()`/`bulkActions()` (`Table::usesGridSemantics()` is the single owner of that decision; see the gesture-layer bullet): `role="grid"`, roving `tabindex`, ↑/↓ move the active row, Enter/Shift+Enter run the primary/secondary, Menu key **and Shift+F10** open the context menu, `?` opens the shortcut help, and each action's `keyboardShortcut()` fires against the active row. **Pointer and keyboard share one active row**: a click marks the row (an Alpine `:class`/`:tabindex` binding, so the marker and the tabstop survive the Livewire morph every update triggers, follow the record through a re-sort, and fall back to the first row when it leaves the page), the active row drops its hover tint so `hover:bg-*` cannot paint over the marker, keys reach the grid only when a **row itself** has the focus (a keystroke inside a row button/editable cell/dropdown is that element's), the grid is inert while a dialog is open, and closing a modal hands the focus back to the active row. Style with `recordActionHover('primary')` (else neutral) and `activeRowClass(...)`. Desktop pointer + keyboard feature; touch cards and sub-rows are excluded by design.
- **Selection gestures (mouse + keyboard), one shared Alpine component.** Every selection surface — the checkboxes, both select-all toggles, the bulk bar, the mobile cards, the keyboard — drives one `wireRecordSelection` component reached via `[data-selection-root]` (optimistic; no per-keystroke roundtrip). Mouse: the **whole selection cell** toggles (`[data-select-cell]`, not just the 16px box, and the cell is registered interactive so the click never reaches a record action), Shift+click ranges from the anchor, mod+click toggles one row anywhere on it, mod+Shift+click adds a block, and **dragging down the checkbox column sweeps** rows in (additive only, mouse only, engages on the first row-changing move so a plain click stays a click). Keyboard: Space toggles + anchors, Shift+↑/↓ ranges, Shift+Home/End and mod+Shift+↑/↓ range to the edge, Home/End/PageUp/PageDown navigate, mod+A selects the page. **A range writes `base ∪ range`, not the range alone** — the snapshot minus the contiguous block around the anchor, so rows selected elsewhere survive; a range gesture **never rewrites `mode`**, which means in "all matching" mode (where the stored list is the *exclusions*) a range **deselects** and mod+A stands down. The anchor is one-shot and invisible; with no anchor of its own the range grows from the far edge of the block the active row sits in. Drop single rows with Space or mod+click, not a range. Keys reach the grid only when a **row itself** has the focus. **The grid reserves the keys it navigates with** — `->onKey()` on Enter/Space/arrows/Home/End/PageUp/PageDown/ContextMenu/F10/`?` throws a `TableConfigurationException` at configuration time rather than dropping the binding silently (a `keyboardShortcut()` on the action itself is only skipped); `Backspace` is deliberately not reserved and acts as a JS-side alias of `Delete`. ARIA: `aria-rowcount`, `aria-multiselectable`, `aria-rowindex` counted through the **whole result set** (not the page), bound `aria-selected` per row, and a polite live region that is in the DOM from the first paint and empty until the first change. The active-row marker is a tint **plus** a leading stripe (`activeRowClass()` replaces both halves) — the tint alone is ~1.1:1, under the 3:1 non-text contrast floor. `Table::shortcutLegend()` returns the same gesture list as data (`ShortcutHint` value objects) for rendering elsewhere.
- **The selection checkbox is drawn by hand, and its mark is wire-table's own icon set.** The row box is a `role="checkbox"` **button**, not a native `<input>` — Alpine owns the selection, and a range sweep has to survive a shift-click a native input would consume — so unlike every other checkbox in the stack (wire-forms' `Checkbox`/`CheckboxList`, wire-core's checkbox entry, all painted by `@tailwindcss/forms`) it has no browser mark to inherit. It draws `table:checkbox-check` and `table:checkbox-indeterminate` from `Support\Icons\TableIconSet`, registered under the `table` prefix and swappable like any other set. These are **checkbox marks, not general-purpose glyphs**: 16x16, stroked at 2 units with round caps, inset clear of the border and stretched to the button's *content* box (no size class — `absolute inset-0` alone, so the mark centres itself inside the 1px border). For a tick in a button or a badge, keep using Heroicons' `check`/`minus`. One owner per state — `Table::getSelectionCheckIcon()` and `getSelectionIndeterminateIcon()`, resolved once per request — and the row, the header select-all and the stacked card all echo the same string, so the two states of one control cannot drift apart. **The tick draws itself in**: the `<span x-show>` transitions `stroke-dashoffset` from 12 to 0 over 200ms, which works only because `stroke-dashoffset` is an *inherited* property and so reaches the `<path>` two levels down. If you replace the glyph, keep the fallback pointing the same way — it declares `stroke-dasharray` and deliberately **no** offset, because an undeclared offset computes to 0, the fully drawn mark; written the other way round (hidden by default, revealed by a utility) a Tailwind build that never emitted the arbitrary utilities would hide the mark for ever and read as a broken checkbox. The three transition attributes cost ~101 B/row, which is why `TablePayloadFuseTest` budgets this cell and why there is no leave stage.
- **Empty-state actions — the way out of an empty table.** `Table::emptyStateActions([...])` renders actions inside the empty state (`->emptyState()` only sets heading/description/icon), typically "create the first record". It accepts a row `Action` **or** a `HeaderAction`, and the empty state is a **record-less** surface: both kinds execute through the header-action host methods (`executeHeaderAction` / `openHeaderActionModal`), never the row pipeline, so `->form()`, `->requiresConfirmation()` and the modal stack all work unchanged while `findHeaderAction()` searches both surfaces. Three rules follow from having no record: only a **static** `->url('/posts/create')` resolves (a per-record `->url(fn ($record) => …)` closure stays unresolved and the action renders as a plain button); an empty-state action needs a **name of its own**, because sharing one with a header action renders both when the table is empty (duplicate `data-testid`, and a `keyboardShortcut()` window listener bound twice); and they are **not** shown when a *filter* emptied the table — that state keeps offering the filter reset, since the records exist behind the filter. Under `stackedOnMobile()` the card empty state renders the same actions from a shortcut-stripped copy, so a browser-test selector matches twice (one per layout) and only one binds the shortcut.
- `Table::rowContextMenu([...actions])` was **removed in 2.0**. Bind the trigger instead: `recordAction(Action::make('edit')->onContextMenu())`. Doing so makes the table a grid (rows carry role/tabindex, the menu becomes keyboard-reachable, ~260 B/row more), and an `ActionGroup` is no longer accepted in the list — bind its actions one at a time.
- **Mobile (`Table::stackedOnMobile()`).** Below the breakpoint each row becomes a card whose hierarchy is five derived slots — title (first column), metric (last right-aligned, e.g. `money()`), meta (badge columns), subtitle, and a label/value grid for the rest — overridable per column (`->mobileMetric()`, `->mobileMeta()`, …) or per table (`->mobileCard(fn (MobileCardConfig $c) => $c->title('number')->metric('total'))`). The header row is hidden, so its controls move into the card view: an always-visible select-all strip, a sort control, sub-row children with their subtotal, the summary totals, and the **empty state** — which is the same canonical surface as the desktop one, so a custom icon/description, the filter-empty reset and `emptyStateActions()` all render on a phone. `->collapseActionsOnMobile()` folds row actions into one dropdown. **Record actions fall back to buttons here**: every record trigger is a desktop one, so a behaviour-only record action renders as an ordinary button on the card (and only there) — one declaration, a gesture on the desktop and a button on a phone. It never doubles an action already in `->actions()`, one referenced by name, or one promoted with `->alsoInRowActions()`, and the fallback buttons count towards `collapseActionsOnMobile()`; the card's copy drops the action's `keyboardShortcut()` (`HasKeyboardShortcut::withoutKeyboardShortcut()`), because a rendered button binds its shortcut as a *window* listener and the cards are in the document at every width. Opt out with `Table::recordActionButtonsOnMobile(false)`. **The toolbar folds too, separately**: `Table::collapseHeaderActionsOnMobile(bool $collapse = true, int $threshold = 2, string|false|null $label = null, string|Icon|null $icon = null)` collapses the *header* actions into one `ActionGroup` dropdown behind a **named** trigger ("Actions" in the brand colour by default — a bare "⋮" is where nobody looks for the page's way in; name what is behind it with `label:`/`icon:`, `label: false` keeps the icon-only "⋮") — no `stackedOnMobile()` needed (the toolbar is the same at every width), switched on the table's `mobileBreakpoint()` (`sm` by default) rather than the stacking breakpoint, counting only actions the viewer may run, and rendering the folded copy shortcut-less for the same window-listener reason.
- `Table::queryString()` persists state to the URL.
- **Project-wide defaults live in `config/wire-table.php`, and a table's own call wins.** `defaults.per_page` / `per_page_options` (positive integers — there is no "all" page size; anything else throws `TableConfigurationException`), `searchable`, `sortable`, `striped`, `hoverable`, `text_input.*` (every `TextInputColumn`) and `notification_driver` (a driver class; `null` = `wire-core.notifications.default`). They are read lazily, so read them through the getters (`getPerPage()`, `isSearchable()`, …) — the properties are `null` until set. `searchable(false)`/`sortable(false)` are enforced **server-side**, not only by hiding the controls: a stale `?sort=`, a saved view or a client writing `tableState` cannot search or re-order such a table (an unsortable table keeps its `defaultSort()`). A saved view's page size is clamped to the table's options like any other.
- Browser-testing hooks: every active part carries a stable `data-testid` — `table-search`, `table-filters-trigger`, `table-filter-reset`, `filter-chip-{name}`, `column-filter-chip-{name}`, `table-column-toggle`, `table-per-page`, `table-page-prev|next|{n}`, `table-sort-{col}`, `table-filter-{col}`, `table-cell-{col}`, `table-editable-{col}`, `table-row` (+ `data-row-key`; mobile `table-card`), `table-select-all` / `table-row-select`, `table-row-expand`, `table-bulk-bar` / `table-deselect`, and `action-{name}` / `header-action-{name}` / `bulk-action-{name}` / `menu-action-{name}` (all with `aria-label`) — so Pest v4 Browser Testing targets them at the user level. An **empty-state action reuses the testid of its kind**, and under `stackedOnMobile()` it matches twice (desktop table + card layout), so select the visible one. Actions and filter options are also reachable by visible text. Column-static render metadata is resolved once per column (`$columnMeta`) instead of per cell.

### Performance

The table renders each cell and each action **once per row**, so per-row cost scales with
rows × columns (× actions). Keep the per-row work cheap and lean on the levers the package
already gives you:

- **Pagination has three modes, and they are not interchangeable.** The default is length-aware:
  numbered pages, and a "showing 1 - 10 of 240" line, paid for with a COUNT query.
  `Table::simplePagination()` drops that COUNT — so it offers **prev/next only** and states no
  total, which is the trade, not a defect. `Table::cursorPagination()` is the fastest over a large
  or shifting set and also offers prev/next, but it can state neither a total nor a position — a
  cursor is an opaque point in an ordering, not an offset. Its controls are driven by
  `setTableCursor()` rather than `previousPage`/`nextPage`, because Livewire's pagination is
  page-based and has no cursor equivalent; the cursor lives in table state and is **dropped
  whenever the set is re-sorted or narrowed**, since it would otherwise point into an ordering that
  no longer exists. Reach for simple when the COUNT is the cost you are avoiding and a total is not
  needed on screen; reach for cursor when the set is large or shifting under the reader.
- **Defer off-screen tables.** `Table::lazy()` returns no rows and runs no query until the
  table scrolls into view (optional `->lazyPlaceholder(...)`). Use it for tables below the fold
  or in tabs. It defers the JS too: the table's Alpine bundles ship with the *deferred* render,
  which is safe because every bundle registers unconditionally (not only from `alpine:init`)
  and Livewire runs a response's new `@assets` to completion before morphing it in. So
  `lazy()` is a lever for query cost, render cost **and** first-paint script weight.
- **Defer action-group menus.** `ActionGroup::make([...])->lazyMenu()` ships only the trigger plus
  a serialized item spec per row and builds the menu client-side on first open — zero per-row menu
  Blade renders (an eager group renders one view per item per row). Opt-in; the default is eager.
  Trade-offs: keyboard shortcuts and `wire:click` modifiers on menu items are not wired in lazy
  mode. Reach for it on large tables whose every row carries a multi-item action dropdown.
- **An interaction inside the table re-renders only the table.** The desktop table, the stacked
  mobile cards and the pagination footer are one Livewire island, and Livewire targets the nearest
  enclosing island automatically — so a sort, a page change, a cell save, a sub-row expansion or a
  row action renders that region alone and leaves the toolbar, the filter panels and the modals
  neither rendered nor morphed. What it saves is the chrome, which is a fixed size, so the share
  depends entirely on the table: 43% less markup on a four-row page, but **36% of the render time
  and only 9% of the bytes** on a 25-column, 20-row page, where 500 cells dwarf the chrome. Judge
  it by the absolute figure — roughly 50-90 kB a render — not by a percentage. Anything fired from
  **outside** the table — the search box, a filter, a column toggle — still renders
  everything, so nothing goes stale. **A poll tick never does**: it answers with changed rows,
  nothing, or the data region alone, sends nothing while an action modal is open, and renders
  in full only when its request also carries something else (`Support\PollCommit`). Never make
  a tick re-render the component — it morphs open modals and floating panels mid-use. You get this without asking for it; the one thing to know is
  that a **custom action whose only effect is outside the table region** (say it writes a property
  that a heading above the table reads) will not repaint that heading when fired from a row. Put
  such an action in the toolbar, or have it dispatch to the surface that owns the heading.
- **`Table::rowPartials()` answers a cell save with the row, not the table.** Opt-in, and worth
  it on exactly one shape: a wide editable grid where the edit *is* the work. On a 25-column,
  20-row page an inline save costs 49.3 ms and 556 kB as a normal render of the data region, and
  **3.2 ms and 26 kB** as one row. The server picks the row at write time and sends it as a
  `wirePartials` effect; the client morphs it into the `wire:partial="row-{key}"` anchor. A table
  without the flag emits no anchor and pays nothing.
  **What you trade:** a row re-rendered on its own keeps its position, so an edit that would move
  the record under the current sort leaves it where it is until the next full render.
A write answers with **everything it moved**, not just the row: the record's card where the table
  is `stackedOnMobile()` (the same record rendered again for the width that hides the table), and
  the totals where it has any (`wire:partial="summary"`, computed over the whole filtered set, so
  any write moves them). Refresh one and not the others and a phone keeps the old value while the
  footer keeps the old total.
  A **grouped** table is covered too: each subtotal ROW carries its own anchor rather than the
  group carrying one, because a subtotal is several `<tr>`s and `wire:partial` addresses a single
  element — the alternative, a `<tbody>` per group, would break the invariant that the delegated
  record-actions controller has exactly one root.
  **The one write that still takes a full render** is a property of the write rather than the
  table: editing the column the table groups BY moves the record to another group, which changes
  the page's shape and is not something any set of regions can describe. `usesRowPartials()` is
  the honest answer to whether the feature is on.
- **With `rowPartials()` on, a poll answers with the rows that moved.** `refreshTable()` — what
  `poll()` calls and what the `live(broadcast: true)` bridge nudges — compares each row on the
  page against a hash of the record it last sent, and queues a partial for the ones that changed:
  nothing at all when nothing moved, the data region's island when the page's *shape* changed (a row
  arrived, left, or moved under the sort — no per-row partial can express that). This is the point
  of the feature on a shared table: a colleague's write repaints their row and leaves whatever you
  have half-typed in a cell of your own alone, where a full re-render morphs the lot.
  **Which rows changed is worked out server-side, from your own page**, and is deliberately not
  carried on the broadcast — the channel is scoped to a model class rather than to a viewer, so
  record keys on it would tell every listener which records exist and change, including ones their
  query would never return. The event stays a bare "something moved" signal.
  It compares a hash of the record's own attributes, so it shares `pollChangeDetection()`'s blind
  spot: a change that never touches the parent row (a child-table rollup, a computed column) is
  invisible to it, and a table that renders one should say so with a `pollChangeDetection()`
  closure.
- **A partial is morphed by Wire's applier, not by Livewire's morph — so client-side row markup
  has to be put back.** `partials.js` drives `Alpine.morph()` itself and reaches neither
  `morph.updating` nor `morph.updated`, so anything the browser added to a row that the server
  render knows nothing about is destroyed by a partial. wire-sortable's drag handle `<td>` is the
  case that proved it: an inline save in reorder mode replaced a three-cell row with the server's
  two-cell one and left that row undraggable, silently, only for rows somebody had edited. Wire
  dispatches `wire:partials-applied` on `document` after each batch with `detail.elements` — the
  anchors it replaced — and a listener repairs what it owns. Tell consumers to use it if they
  decorate rows from their own JS. It is an **announcement, not a hook**, deliberately:
  `window.Livewire.trigger` is public and firing `morph.updating` from this path would let a guard
  written for a whole-table render `skip()` the very cell the partial exists to update. Only
  `npm run verify:drivers` can see this class of bug; Pest reads markup that never had the handle.
- **A row costs what its cells cost, and little else.** The row body is assembled in PHP from
  markup Blade compiled once per table — the `<tr>` tag, the selection cell, the three sub-row
  expander shapes, the action cell, one skeleton per column — so a page of rows runs no per-row
  view render and emits no per-row conditional markers. That is worth **850-1000 B per row**
  against the same table before it, on top of the per-cell work. Two things follow for anyone
  extending the row: every child of a `<tr>` whose *presence* varies carries a `wire:key`
  (`ctx-`/`sel-`/`exp-`, and `act-{key}-{name}` on each record-scoped action button) because that
  is what pairs them through a morph now — add a conditional child without one and it will pair
  against its neighbour; and a per-row `@include` re-introduces the O(rows) view renders the
  engine exists to remove, which `TableRenderCountTest` fails on.
- **Opening an action modal does not re-render the table.** Any action with a modal renders
  `wire:island="action-modals"`, and a call targeting an island makes Livewire skip the component
  render and return the island alone — the modal instead of every cell behind it (9.5 KB against
  125 KB on a four-row table; the island is a fixed size, so the gap widens with rows). Two
  consequences worth knowing: an action that **opens** a modal should not also mutate what the
  table shows, because nothing behind the modal re-renders until the next request — do the write on
  submit, which is a normal full render; and a modal opened any other way (a keyboard shortcut, the
  row context menu, `openOn()`, a method call from a test) still renders exactly as before.
- **An inline edit re-renders the table, and the cell survives it.** `TextInputColumn` /
  `ToggleColumn` / `SelectColumn` commit through `updateTableCell`, which renders — everything
  derived from the written value (summaries, rollups, a badge computed from the same column, the
  row's position under the current sort) is stale otherwise. The cell keeps its own Alpine state
  because its root carries `wire:ignore.self`, which is also why the fresh value cannot reach it
  through that root: Livewire stops updating an ignored element's own attributes after the first
  render. It arrives on a **sync node**, a child element the morph does update and the cell
  watches. Never move `data-server-value` / `data-record-version` back onto the ignored root, and
  never write one without the other — that wakes the observer against a frozen partner and the
  edit vanishes from the screen a second after it reached the database.
  `Table::refreshAfterEdit(false)` opts back out for a table where the render is expensive and
  nothing on screen depends on the edited value.
- **Every skip goes through `WithTable::skipTableRender()`, every view change through
  `markTableViewChanged()`.** Never call `skipRender()` directly. Livewire merges everything queued
  for one component into a single request, so a cell save, a fill or a poll tick can arrive
  together with the user changing the page size, search, a filter, the sort, the page or which
  columns are visible. Skipping there answers with no HTML at all — the new state lands in the
  snapshot while the browser keeps the old rows until the user does something else. Note the
  asymmetry that makes `markTableViewChanged()` more than a flag: property updates (the per-page
  select) always reach the component before any call, but `setPage()` is itself a *call*, and the
  browser queues the cell edit **first** — the pagination click blurs the input on its way — so the
  skip is already granted by then and has to be taken back.
- **Relation display never N+1s.** Dot-notation columns (`TextColumn::make('company.name')`)
  eager-load via `with()` for display — never add a manual per-row query in `displayUsing`.
- **Eager-load closure relations.** A relation dereferenced ONLY inside a closure —
  `->displayUsing(fn ($s, $r) => $r->company->name)`, `->url(fn ($r) => route('x', $r->team))`,
  `->color(fn ($s, $r) => $r->status->tint)` — has no column path, so the planner cannot
  discover it and it lazy-loads once per row (a large N+1). Add the hint
  `->loadRelations('company')` (or `->loadRelations(['company', 'team'])`) on the column,
  or eager-load on the base query (`->query(User::with('company'))`). The hint flattens
  the query count regardless of row count.
- **Summaries and rollups are one SQL query**, not one per column (`SummaryBatch`); grand totals
  compute in SQL. Prefer `->summarize(...)` over counting in PHP.
- **Keep per-row closures cheap — they run for every row.** `displayUsing`, `colorUsing`,
  `iconUsing`, `visibleForRecord`, `rowColor` and `rowClass` closures execute per record. Pick
  the cheapest form: a fixed `->color('success')` or a static `->colors([...])` / `->icons([...])`
  map over a `->colorUsing(fn ...)` when the mapping does not actually depend on runtime state; an
  enum implementing `HasColor`/`HasIcon` resolves with no closure at all. Never open a DB query or
  resolve a container binding inside one of these closures.
- **Do not render Blade per row inside your own code.** A custom `->view(...)` or `displayUsing`
  is already invoked once per cell by the engine — do not nest another `view(...)->render()` or a
  Blade include of a primitive (spinner, icon, divider) inside it. Return a value or a prebuilt
  `Htmlable`; let the cell partial place it. Resolve icons through the `IconManager` (an enum
  `HasIcon`, `->icon('check')`), never a hardcoded `<svg>` — the manager output is what gets reused,
  and hardcoded SVG also breaks theming.
- **Split a heavy screen into a child component.** `WithTable` and `WithActions` on the same
  Livewire component both re-render on every interaction; for a large table with its own action
  workflow, put the table in a dedicated child component so an action elsewhere on the page does
  not re-render the whole grid.
- **Column-static metadata is already resolved once per column** — `Support\ColumnRenderPlan`
  owns it and the table view reads it as `$columnMeta`, keyed by column name and carrying each
  column's compiled `<td>` skeleton. Hidden columns / non-executable actions short-circuit to an
  empty render. You get all of that for free; never re-derive it per cell.

Use `describe-table` on an existing component to see its resolved columns, filters and actions.
<!-- wire-boost:guidelines:end -->
