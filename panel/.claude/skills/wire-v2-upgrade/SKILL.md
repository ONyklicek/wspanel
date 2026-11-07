---
name: wire-v2-upgrade
description: Migrate an application from wireStack 1.x to 2.0 — Livewire 4, the removed trait shims and Widget::lazy(), the new row markup, gestures, JavaScript assets and the routing renames.
---

# wireStack 1.x → 2.0 Upgrade

## When to use this skill

Use when an application on wire-core / wire-forms / wire-table / wire-sortable `1.x` is being moved to
`2.0`, or when code that worked before the update stopped working after it (a missing trait, a dead
Alpine controller, a selection that no longer responds, a `use` statement that no longer resolves).

The full guide is `docs/start/upgrade.md` in the corpus — read it with `fetch-wire-doc` before touching
the composer constraints. This skill is the checklist to work through it.

## Workflow

1. **Upgrade Laravel and Livewire first.** 2.0 requires **Livewire 4**; the 1.x line stays on Livewire 3
   and no release runs on both. Floors are PHP 8.2+, Laravel 12.61+ / 13.12+,
   `nyoncode/laravel-package-toolkit` `^2.5`.

   ```bash
   composer require livewire/livewire:^4.0
   php artisan optimize:clear
   composer update "nyoncode/wire-*"
   ```

2. **Work the checklist below**, grepping the application for each signal.
3. **Re-publish anything published from 1.x** — views especially; a stale copy keeps working and silently
   keeps the old behaviour.
4. **Clear caches, rebuild assets, run the suite.**

   ```bash
   php artisan view:clear && php artisan config:clear && npm run build
   ```

5. **Run `validate-wire-component`** on every table, form and infolist touched by the upgrade.

## Checklist

### Removed — the code will not run until it is changed

**The nine trait shims under `NyonCode\WireCore\Concerns\`.** Each was a `class_alias()` of the trait of
the same name under `Actions\Concerns\`, so the migration is the import line:

```php
// before
use NyonCode\WireCore\Concerns\HasIcons;

// after
use NyonCode\WireCore\Actions\Concerns\HasIcons;
```

The names: `HasButtonStyles`, `HasColor`, `HasDynamicProperties`, `HasIcons`, `HasKeyboardShortcut`,
`HasLifecycle`, `HasLoadingState`, `HasModal`, `HasVisibility`. For colors prefer
`Foundation\Concerns\HasColor` — the canonical owner the `Actions` one aliases.
`NyonCode\WireTable\Concerns\TableQueryService` went the same way; it lives in `Services\`.

**`Widget::lazy()` / `Widget::isLazy()`.** Deleting the calls is the whole migration — no widget view ever
read the flag, so nothing rendered differently. To actually defer, defer the component:
`<livewire:my-dashboard lazy />`. Per-widget deferral does not exist.

**The registration and routing renames.** No aliases were kept:

| Before | Now |
| --- | --- |
| `Core\Resources\Contracts\NavigationSource` | `Foundation\Registration\Contracts\RegistrySource` |
| `NavigationSource::navigableClasses()` | `RegistrySource::registeredClasses()` |
| `WirePanels\Resources\Contracts\ProvidesResourcePages` | `WireCore\Foundation\Routing\Contracts\ProvidesPages` |
| `WirePanels\Resources\Contracts\ConfiguresResourceRoutes` | `WireCore\Foundation\Routing\Contracts\ConfiguresRoutes` |
| `WirePanels\Routing\RoutePage` | `WireCore\Foundation\Routing\RoutePage` |

The menu, the router and the ⌘K palette all read one `Catalog`, so a hand-written `key => url` map beside
the routes can go: a menu item carries `getUrl()` and a search result carries its record's URL.
`Workspace` and `GlobalSearch` changed constructors — only hand-built instances are affected.

### Silent — it keeps working and quietly does the wrong thing

**Published views from 1.x.** `tables/index.blade.php` no longer contains the row body (it moved to
`partials/data-region.blade.php`, rendered from PHP by `Support\RowRenderer` / `Support\CardRenderer`),
and the gesture markup is not in a 1.x copy. Laravel prefers the published file, so it keeps the old cost,
misses `rowPartials()`, and can select the wrong rows.

```bash
php artisan vendor:publish --tag=wire-table::views --force
```

Re-apply customisations on top — or delete the copy and use theming instead.

**Overridden field views for the seven converted types** — `DateTimePicker`, `TimePicker`, `Select`,
`Tags`, `Rating`, `RichEditor`, `MarkdownEditor`. The inline `x-data` object literal is now an
`Alpine.data()` factory; call it with a config object, and keep `state` in the markup because
`$wire.entangle` is an Alpine magic that cannot move into a bundle:

```blade
<div x-data="wireDateTimePicker({ state: $wire.entangle('data.at'), hasDate: true })">
```

An overriding view must still include `wire-forms::partials.field-assets`, or the factory is absent and
the field does nothing.

**A multiple-file upload merge written by hand.** Livewire 4 appends new uploads to a multiple-file field
itself and wire no longer fills that gap, so an `updated()` hook doing the same merge now counts entries
twice — delete it.

**Row markup walked by position.** The per-row morph markers (`<!--[if BLOCK]>`) are gone; conditional
children carry `wire:key` instead (`ctx-`, `sel-`, `exp-`, `act-{key}-{name}`). A `:nth-child()`, a
`querySelector` chain stepping over comment nodes, or a browser test asserting on them needs revisiting.
`[data-row-key]`, `[data-testid]`, `[data-column]` and `tbody tr` are untouched.

**`window.Sortable` is no longer provided** — SortableJS is compiled into the wire-sortable bundle and
`wire-sortable.sortablejs_cdn` defaults to `null`. Reordering is unaffected; only own code reading the
global. Set the config key back, or bundle SortableJS in `app.js`.

**Livewire's endpoints moved** to `/livewire-{hash}/…`. Firewall, CDN and bypass rules matching the old
prefix by hand need updating; wire's asset routes were never under it.

### Opt-in — nothing changes until it is asked for

- **`->gestures()`** — ranges, the drag sweep and keyboard navigation are off per table (or on globally
  via `wire-table.defaults.gestures`). Checkboxes, both select-all controls and the bulk bar are
  unchanged without it.
- **`->onKey()` on a navigation key now throws** instead of being dropped silently — that binding was
  already dead code. Reserved: `Enter Space ArrowUp ArrowDown Home End PageUp PageDown ContextMenu F10 ?`.
- **Range gestures in "all matching" mode deselect** rather than collapsing the selection.
  `getSelectedRecordKeys()` returns `[]` in that mode by design — use `selectedRecordsQuery()` or
  `eachSelectedRecord()`.
- **`@wireStackScripts` in the layout `<head>`** — additive, but it is what stops controllers dying after
  a `wire:navigate` visit (`wireRecordSelection is not defined`). Delete any `@include` of package script
  partials in favour of it.
- **`->rowPartials()`** — a write answers with the regions it moved. The trade is that a re-rendered row
  keeps its position until the next full render.
- **`->dataSource(new CollectionDataSource([...]))`** — a table can read from something other than
  Eloquent. `->model()` / `->query()` and `Model $record` closures are unchanged; a non-Eloquent source is
  restricted and throws `UnsupportedQueryAspectException` for what it cannot answer.
- **Behaviour-only record actions render as buttons on a mobile card**; opt out with
  `->recordActionButtonsOnMobile(false)`.

### Removed in 2.0 — these now fatal

Every shim that 1.x marked "will be removed in v2.0" is gone. `Action::hiddeLabel()` → `hideLabel()`;
`Table::polling()` → `poll()`; on **`ActionHalt` only**, `modalHeading()` → `heading()`,
`modalDescription()` → `body()`, `modalIcon()` → `icon()`, `modalSubmitLabel()` → `submitLabel()`,
`modalCancelLabel()` → `cancelLabel()`, `modalWidth()` → `width()`, `formValidation()` → `validation()`
(the identically named setters on an **action** are canonical and unchanged);
`TableNotification` / `TableNotificationManager` → `Notification` / `NotificationManager`;
`confirmTableAction()`, `executeConfirmedAction()`, `closeConfirmationModal()`, `confirmBulkAction()`
and `getConfirmationModalData()` → the halt modal API; `WireForms\Components\Layout\{Section,Fieldset,Grid}`
→ `WireCore\Foundation\Schema\{Section,Fieldset,Grid}`.

Five more that dragged from 1.x without naming a release went in the same sweep:
`Table::rowContextMenu([...])` → `recordActions([Action::make('…')->onContextMenu()])` (and the table is then a
**grid**: rows carry role/tabindex, ~260 B/row more, menu reachable from the keyboard; an `ActionGroup` in the
list is no longer accepted); `TextInputColumn::formatForSave()`/`formatAfterLoad()` → `dehydrateState()`/`hydrateState()`;
`flattenSubRows()`/`isFlattenSubRows()`/`toggleFlattenMode()` → `subRowsDefaultExpanded()`/`isSubRowsDefaultExpanded()`/`toggleAllRowExpansion()`;
and `WithTable`'s legacy magic properties (`$this->tableSearch`, `$tableFilters`, `$flattenMode`, …) →
`$this->tableState->get()/set()`. **Check `$queryString` arrays**: one naming a legacy property now fails with
Livewire's "property does not exist" — use `Table::queryString()` instead.

### Still deprecated — worth clearing while here

`WithTable::__get`-era state reads are gone, but `Table::debugQueryPlan()`-style helpers and the
`TableQueryingPayload::$forceSortColumn` / `$forceSortDirection` constructor params remain deprecated: the typed
payload does not consume them, the array hook's `force_sort_column` key does.

## Rules

- **Read `CHANGELOG.md` for every version being crossed**, not only the guide — breaking changes are
  listed per release under a **Breaking Changes** heading, with the moved class names.
- **Upgrade in order**: Laravel, then Livewire 4, then the wire packages. A floor belongs to a dependency,
  so an app below it cannot resolve the packages whatever their own constraint says.
- **Diff every published file** — `config/wire-*.php`, `resources/views/vendor/wire-*/…`,
  `lang/vendor/wire-*/…` do not update themselves.
- **Do not add compatibility shims** for the renamed registration/routing contracts; update the callers.
- If a class or method in the docs no longer resolves after the update, search `CHANGELOG.md` for the old
  name before assuming it is gone.
