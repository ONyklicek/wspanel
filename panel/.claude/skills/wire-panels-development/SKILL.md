---
name: wire-panels-development
description: Declare a resource and its pages with wire-panels — static identity, instance surfaces, the registries the menu and the router read, and the navigation entry.
---

# wire-panels Development

## When to use this skill

Use when an entity should get its own screens — a list, a create/edit form, a read-only view — or when
changing what the menu, the router or the search palette shows for one. Also when those screens live
under a company in the URL — a tenant zone.

## Workflow

1. `describe-resource` first — it also reports whether a resource manages its trash, the parent of a nested
   one, and every page with the permission its route requires. It reports what is already registered, which surfaces each declares, and the
   navigation entry — a second resource on the same key is refused, not merged.
2. Start from `php artisan make:wire-resource Order` (`--generate` reads fields and columns off the table,
   `--view`, `--simple`, `--soft-deletes`, `--register`), then edit — or write the resource by hand:
   `DescribesRecords` for identity, plus one contract per surface it really has.
3. Register it in `config('wire-core.resources')`, or name its folder in `config('wire-core.discover.resources')`
   so every resource there registers itself. `php artisan wire:resources` shows what is registered and routed.
4. Add pages only for the surfaces the resource declares, and give each new concrete `WithTable`/`WithForms`/
   `WithActions` host a `phpstan.neon` excludePaths entry.

## Patterns

```php
use NyonCode\WireCore\Core\Resources\Concerns\DescribesRecords;
use NyonCode\WireCore\Core\Resources\Contracts\DescribesResource;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WirePanels\Resources\Contracts\ProvidesResourceTable;

class OrderResource implements DescribesResource, ProvidesNavigation, ProvidesResourceTable
{
    use DescribesRecords;

    // Key, label and plural derive from the model: order-lines / Order Line / Order Lines.
    public static function modelClass(): ?string
    {
        return Order::class;
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make()->icon('outline:shopping-cart')->group('sales')->sort(10);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('number')->searchable()]);
    }
}
```

```php
// A page names the resource; the heading defaults to the plural label.
class ListOrders extends ListPage
{
    protected static ?string $resource = OrderResource::class;
}

// Edit and view take a record KEY, not a model — a hydrated model in the
// Livewire snapshot is larger than the key and stale by the next request.
@livewire(EditOrder::class, ['record' => $order->getKey()])

// Actions beside the heading run against the page's record.
class EditOrder extends EditPage
{
    protected static ?string $resource = OrderResource::class;

    protected function headerActions(): array
    {
        return [$this->deleteHeaderAction()];
    }
}
```

```php
// A tenant zone: {tenant} after the prefix, wire.tenant, and the bare /app → your company.
Route::middleware(['web', 'auth'])->prefix('app')
    ->group(fn () => Route::wire('panel', zone: 'app', tenant: 'path'));

// Or from config/wire-core.php: 'routes' => ['groups' => ['app' => ['uses' => 'panel', 'prefix' => 'app', 'tenant' => 'path']]].
class User extends Authenticatable implements HasTenants
{
    use InteractsWithTenants;   // membership over wire-core.tenancy.members_table
}
```

## Rules

- **A resource is several small contracts, not one class.** `DescribesResource` (wire-core) is identity —
  `key()`, `modelClass()`, `label()`, `pluralLabel()`, all **static**. Surfaces are separate:
  `ProvidesResourceForm` (wire-forms), `ProvidesResourceInfolist` (wire-core), `ProvidesResourceTable`
  (wire-panels). Declare only what the entity has.
- **Static identity, instance surfaces.** A menu asks for a label and `ResourceRegistry::forModel()` routes
  a model to its owner *before anything is instantiated*, so metadata must answer without an instance.
- **One `form()` serves create and edit.** Persistence stays the form's; the page binds the model and calls
  `save()`. The page owns host wiring (`public ?array $data`, `statePath('data')`), the resource does not.
- **A half-declared page throws rather than rendering empty** — no resource *and* no `table()`, or a
  resource with no `ProvidesResourceTable`. Empty reads as "no records" instead of as a mistake.
- **`group()` takes a key, not a heading.** Register `NavigationGroup::make('sales')->label(…)->sort(10)`
  from a provider; a group nothing declares still works with a derived heading. Hiding a group hides its
  entries, which is the point.
- **Never inject `ResourceRegistry` into a new surface.** Read `Foundation\Registration\Catalog` and filter
  by the surface's own opt-in (`ProvidesNavigation`, `ProvidesPages`, `GloballySearchable`) — registered ≠
  listed ≠ routed ≠ searchable.
- **Which entry is active is `ActiveNavigation`'s**, matched exactly, never by prefix. An entry that means a
  whole section says `->activeWhen('settings/*')`, which *replaces* the convention rather than adding to it.
- `ViewPage` composes the action runtime and no form or table trait — read-only means no state to bind
  and nothing to submit. Like every page hosting actions it is in the PHPStan exclusions.
- **Header actions go in `headerActions()` on the page**, never in a second action engine. *New* is on the
  list by default; *Delete* is opt-in with `$this->deleteHeaderAction()` (policy first, then the edit page's
  permission, then nobody). A page that is not a resource surface — a board, a report — extends `Pages\Page`
  and names its content view.
- **Pages are composed capabilities.** Header actions go in `headerActions()` (list: *New* by
  default; edit/view: `deleteHeaderAction()`, `restoreHeaderAction()`, `forceDeleteHeaderAction()` on
  request); widgets in `headerWidgets()` / `footerWidgets()` (drawn, not hosted — polling widgets stay on a
  `DashboardPage`); list tabs in `tabs()` of `ListTab`s (narrow the base query, `?tab=` in the URL). Create and
  edit pages warn before unsaved input is lost; turn it off with `warnsAboutUnsavedChanges(): bool`.
- **A page that is not a resource surface extends `Pages\Page`** and names its content `$view`
  (`make:wire-page TaskBoard`, or `--resource=Order` for a record page that becomes one of the record's tabs).
- **Whole-resource behaviour is a contract on the resource, never a page switch**: `ManagesTrashedRecords`
  (trashed filter, restore/force delete — model must use `SoftDeletes`) and `NestedResource`
  (`parentResource()` + `parentRelationship()`; routed under `{parent}`, one level deep). A resource small
  enough for modals is one `ManagePage` as `pages()`'s `index`.
- **A standalone page registers itself**: list it in `config('wire-panels.pages')` or discover its folder with
  `config('wire-core.discover.pages')`; `$slug`, `$navigationLabel`, `$navigationIcon`, `$navigationGroup`,
  `$navigationSort`, `$permission` and `$shouldRegisterNavigation` place it. No wrapper resource needed.
- **Inside a tenant zone, never pass `tenant` by hand.** `wire.tenant` sets `URL::defaults()`, so
  `OrderResource::url()`, the menu, search and redirects already carry it; it forgets the parameter, so no
  `mount()` gets it either. Read the company from `app(CurrentTenant::class)->get()`. A stranger, an unknown
  slug and a guest are one **404**, never a 403. Tenant-owned models use `BelongsToTenant` (wire-core); the
  company screens — registration, profile, members — are `nyoncode/wire-module-tenants`, not a page to write.
