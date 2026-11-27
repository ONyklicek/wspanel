<?php

namespace App\Wire\Actions\Concerns;

use App\Support\RunningTasks;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * An action whose work goes on in the background shows it on its button:
 * for as long as the task runs, the icon is a turning arrow and the button
 * waits (disabled), instead of looking as if the click did nothing.
 */
trait ShowsRunningTask
{
    private ?Closure $busyWhile = null;

    /**
     * Busy while a task with one of these ops runs (for the row's record, on
     * a row), or while the Closure says so.
     *
     * @param  string|list<string>|Closure  $ops  Closure: fn (?Model $record): bool
     */
    public function busyWhile(string|array|Closure $ops): static
    {
        $this->busyWhile = $ops instanceof Closure ? $ops
            : fn (mixed $record = null): bool => RunningTasks::has($ops, $record instanceof Model ? $record : null);

        return $this;
    }

    public function isBusy(mixed $context = null): bool
    {
        return $this->busyWhile !== null && (bool) ($this->busyWhile)($context instanceof Model ? $context : null);
    }

    public function getIcon(mixed $context = null): ?string
    {
        return $this->isBusy($context) ? 'outline:arrow-path' : parent::getIcon($context);
    }

    public function isDisabled(mixed $context = null): bool
    {
        return $this->isBusy($context) || parent::isDisabled($context);
    }

    /** @return array<string, string> */
    public function getExtraAttributes(mixed $context = null): array
    {
        return parent::getExtraAttributes($context) + ($this->isBusy($context) ? ['data-busy' => 'true', 'aria-busy' => 'true'] : []);
    }
}
