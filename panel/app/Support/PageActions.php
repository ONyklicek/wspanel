<?php

namespace App\Support;

use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Actions\ActionGroup;

/**
 * The header of a record's page, laid out the same everywhere: at most one
 * primary (coloured) action, the rest grey, and the destructive ones (delete,
 * remove) in the "Další" menu, last (alone, a grey button at the end: a
 * menu of one is drawn as its button). Their confirmation stays red.
 */
final class PageActions
{
    /** Names of the actions that remove the record. */
    private const DESTRUCTIVE = ['delete', 'deleteDomain', 'deleteZone', 'drop', 'remove', 'destroy'];

    /**
     * @param  list<Action|ActionGroup|null>  $actions  in the order shown
     * @param  string|null  $primary  the action that stays coloured
     * @param  list<Action>  $more  rarely used ones for the menu (before the destructive)
     * @return list<Action|ActionGroup>
     */
    public static function arrange(array $actions, ?string $primary = null, array $more = []): array
    {
        $visible = [];
        $danger = [];
        foreach (array_filter($actions) as $action) {
            if ($action instanceof Action && in_array($action->getName(), self::DESTRUCTIVE, true)) {
                $danger[] = $action->color('danger');

                continue;
            }
            if ($action instanceof Action && $action->getName() !== $primary) {
                $action->color('gray');
            }
            $visible[] = $action;
        }
        $menu = [...array_filter($more), ...$danger];
        if (count($menu) === 1) {
            // A menu of one is drawn as its button: then a quiet one, last.
            $visible[] = $menu[0]->color('gray');
        } elseif ($menu !== []) {
            $visible[] = ActionGroup::make($menu)->label(__('Další'))->icon('outline:ellipsis-vertical');
        }

        return $visible;
    }
}
