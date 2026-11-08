<?php

namespace App\Livewire\Pages;

use NyonCode\WirePanels\Pages\Page;

/** Where the signed-in user is told about problems and deploys. */
class NotificationsPage extends Page
{
    protected static string $view = 'livewire.pages.notifications';

    protected ?string $title = 'Notifikace';

    protected static ?string $slug = 'notification-channels';

    protected static ?string $navigationLabel = 'Notifikace';

    protected static ?string $navigationIcon = 'outline:bell-alert';

    protected static ?string $navigationGroup = 'network';

    protected static int $navigationSort = 90;

    /** "notifications" is the history of wire-module-notifications. */
    public static function key(): string
    {
        return 'notification-channels';
    }
}
