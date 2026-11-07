<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Order Column
    |--------------------------------------------------------------------------
    |
    | The default database column used for storing the row sort position.
    |
    */
    'order_column' => 'sort_order',

    /*
    |--------------------------------------------------------------------------
    | SortableJS CDN
    |--------------------------------------------------------------------------
    |
    | SortableJS is now bundled into the package's own asset
    | (dist/wire-sortable.js), so the default is null: reordering works offline
    | and under a strict CSP with no external request.
    |
    | Set a URL here only if your application needs a global `window.Sortable`
    | of its own — the drag controller uses the bundled copy either way, so a
    | CDN script is loaded in addition to it, never instead of it. Apps that
    | already set this key keep working unchanged.
    |
    */
    'sortablejs_cdn' => null,

    /*
    |--------------------------------------------------------------------------
    | Animation Duration
    |--------------------------------------------------------------------------
    |
    | Animation duration in milliseconds for the drag & drop effect.
    |
    */
    'animation' => 150,

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model class for users. Used by ReorderableColumnOrder
    | for the belongsTo relationship.
    |
    */
    'user_model' => 'App\\Models\\User',

    /*
    |--------------------------------------------------------------------------
    | User Key Type
    |--------------------------------------------------------------------------
    |
    | The primary key type of your user model, used by the migration to type
    | the reorderable_column_orders.user_id column. Use 'uuid' or 'ulid' for
    | non-integer auth keys; 'id' (default) assumes an unsigned bigint.
    |
    | Supported: 'id', 'uuid', 'ulid'.
    |
    */
    'user_key_type' => 'id',

];
