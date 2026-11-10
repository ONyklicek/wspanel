<?php

namespace App\Wire\Actions;

use App\Wire\Actions\Concerns\NamesItsModal;
use NyonCode\WireCore\Actions\HeaderAction as WireHeaderAction;

/** A table's header action, with its modal named after what it does. */
class HeaderAction extends WireHeaderAction
{
    use NamesItsModal;
}
