<?php

namespace App\Livewire\Concerns;

/**
 * A field's afterStateUpdated() inside a page's action modal (a header
 * action's form): WireStack runs it on a table's modals only, so a live
 * field of a page's modal changed and nothing followed. The state path of
 * those fields is mountedActions.<depth>.data.<field>.
 *
 * For a page that composes WireStack's WithActions (its pages do).
 */
trait ReactsInActionForms
{
    public function updatedMountedActions(mixed $value, string $path): void
    {
        if (! preg_match('/^\d+\.data\./', $path)) {
            return;
        }
        $form = $this->getActionModalFormInstance();
        if ($form !== null) {
            $this->dispatchAfterStateUpdated([$form], 'mountedActions.'.$path, null);
        }
    }
}
