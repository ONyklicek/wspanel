<?php

namespace Tests\Concerns;

use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportTesting\Testable;

/**
 * Drives WireStack actions the way the browser does: open the modal, fill its
 * form, submit (so the form's validation runs).
 */
trait RunsWireActions
{
    private const TABLE_FRAMES = 'tableState.modal.actions';

    /** @param  array<string, mixed>  $data */
    protected function tableAction(Testable $component, Model|string|int $record, string $action, array $data = []): Testable
    {
        $key = (string) ($record instanceof Model ? $record->getKey() : $record);
        $component->call('openActionModal', $key, $action);
        if (empty($component->get(self::TABLE_FRAMES))) {
            return $component;
        }
        $this->fillActionForm($component, $data, self::TABLE_FRAMES);

        return $component->call('submitActionModal');
    }

    /** @param  array<string, mixed>  $data */
    protected function headerTableAction(Testable $component, string $action, array $data = []): Testable
    {
        $component->call('openHeaderActionModal', $action);
        if (empty($component->get(self::TABLE_FRAMES))) {
            return $component;
        }
        $this->fillActionForm($component, $data, self::TABLE_FRAMES);

        return $component->call('submitActionModal');
    }

    /**
     * An action on a page (header action or one declared in actions()).
     *
     * @param  array<string, mixed>  $data
     */
    protected function pageAction(Testable $component, string $action, array $data = []): Testable
    {
        $component->call('mountAction', $action);
        if (empty($component->get('mountedActions'))) {
            return $component;
        }
        $this->fillActionForm($component, $data, 'mountedActions');

        return $component->call('callMountedAction');
    }

    /**
     * Set page form fields one by one (as typing does, so live hooks run).
     *
     * @param  array<string, mixed>  $data
     */
    protected function fillForm(Testable $component, array $data, string $path = 'data'): Testable
    {
        foreach ($data as $key => $value) {
            $component->set("{$path}.{$key}", $value);
        }

        return $component;
    }

    /** @param  array<string, mixed>  $data */
    private function fillActionForm(Testable $component, array $data, string $frames): void
    {
        $depth = count((array) $component->get($frames)) - 1;
        foreach ($data as $key => $value) {
            $component->set("{$frames}.{$depth}.data.{$key}", $value);
        }
    }

    /** Whether a row action of a table page is offered for the record. */
    protected function tableActionVisible(Testable $component, string $action, Model $record): bool
    {
        $found = (fn () => $this->findAction($action))->call($component->instance());

        return $found !== null && ! $found->isHidden($record);
    }

    /** Whether a header action of a list page is offered. */
    protected function headerActionVisible(Testable $component, string $action): bool
    {
        $found = (fn () => $this->findHeaderAction($action))->call($component->instance());

        return $found !== null && ! $found->isHidden();
    }
}
