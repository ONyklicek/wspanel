<?php

namespace App\Wire\Actions;

use App\Wire\Actions\Concerns\NamesItsModal;
use NyonCode\WireCore\Actions\Action as WireAction;

/** The panel's actions: WireStack's, with modals named after what they do. */
class Action extends WireAction
{
    use NamesItsModal;
}
