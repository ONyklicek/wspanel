<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use NyonCode\WireCore\Core\Capabilities\CapabilitySet;
use NyonCode\WireCore\Core\Data\DataSource;
use NyonCode\WireCore\Core\Data\EloquentRecord;
use NyonCode\WireCore\Core\Data\PagingRequest;
use NyonCode\WireCore\Core\Data\RecordContract;
use NyonCode\WireCore\Core\Query\QueryPlan;
use NyonCode\WireTable\Data\CollectionDataSource;
use NyonCode\WireTable\Data\CollectionRow;

/**
 * CollectionDataSource whose looked-up records unwrap to a CollectionRow model.
 *
 * WireStack 2.9 lists array rows as CollectionRow models, but a row action
 * resolves the clicked row through resolveRecord(), whose ArrayRecord unwraps
 * to a plain array, and the action engine requires a Model. Wrapping the row
 * here gives actions the same object the list rendered.
 */
final class RowsDataSource implements DataSource
{
    private readonly CollectionDataSource $inner;

    /** @param  iterable<int, array<string, mixed>>  $rows */
    public function __construct(iterable $rows, private readonly string $keyName = 'id')
    {
        $this->inner = new CollectionDataSource($rows, $keyName);
    }

    public function paginate(QueryPlan $plan, PagingRequest $paging): LengthAwarePaginator|Paginator|CursorPaginator
    {
        return $this->inner->paginate($plan, $paging);
    }

    public function get(QueryPlan $plan): Collection
    {
        return $this->inner->get($plan);
    }

    public function chunk(QueryPlan $plan, int $size, callable $callback): void
    {
        $this->inner->chunk($plan, $size, $callback);
    }

    public function count(QueryPlan $plan): int
    {
        return $this->inner->count($plan);
    }

    public function resolveRecord(int|string $key): ?RecordContract
    {
        $record = $this->inner->resolveRecord($key);

        return $record === null ? null : new EloquentRecord(CollectionRow::from($record->unwrap(), $this->keyName));
    }

    public function resolveRecords(array $keys): Collection
    {
        return $this->inner->resolveRecords($keys)
            ->map(fn (RecordContract $record): RecordContract => new EloquentRecord(CollectionRow::from($record->unwrap(), $this->keyName)));
    }

    public function capabilities(): CapabilitySet
    {
        return $this->inner->capabilities();
    }

    public function changeToken(QueryPlan $plan): ?string
    {
        return $this->inner->changeToken($plan);
    }
}
