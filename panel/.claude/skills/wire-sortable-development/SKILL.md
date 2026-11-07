---
name: wire-sortable-development
description: Add drag & drop row and column reordering to wire-table using the wire-sortable plugin and Table macros.
---

# wire-sortable Development

## When to use this skill

Use when a wire-table should support drag & drop reordering of rows or columns.

## Workflow

1. Ensure wire-sortable is installed and `php artisan wire-sortable:install` has published its config and
   the `reorderable_column_orders` migration.
2. Enable reordering with the `Table` macros inside the component's `table()` method.

## Patterns

```php
public function table(Table $table): Table
{
    return $table
        ->query(Task::query())
        ->reorderable('position')
        ->paginatedWhileReordering()
        ->columns([
            TextColumn::make('title'),
        ]);
}
```

## Rules

- The reordering macros (`reorderable`, `alwaysReorderable`, `columnReorderable`,
  `paginatedWhileReordering`) only exist when wire-sortable is installed.
- The order column defaults to `wire-sortable.order_column` (`sort_order`); pass a column name to override.
- The drag handle is rendered from `Table::getDragHandleHtml()` (a Blade partial) — do not hand-build the
  handle markup in JS.
- **A board (kanban) is `Board` + `WithBoard`**, not a table: `Board::make()->model(Task::class)->groupBy('status')
  ->lanes(TaskStatus::class)->cardTitle('title')->orderColumn('position')`. Host it on a wire-panels `Page`
  (`use WithBoard;` + `protected static string $view = 'wire-sortable::board.content';`) or any Livewire
  component. The drag is Livewire's `wire:sort` — no script to write; guard transitions in
  `canMoveBoardCard()`, react in `boardCardMoved()`.
