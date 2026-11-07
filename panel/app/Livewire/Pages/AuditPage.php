<?php

namespace App\Livewire\Pages;

use NyonCode\WirePanels\Pages\Page;

/** Everything the privileged agent did (its own log; the panel cannot write it). */
class AuditPage extends Page
{
    protected static string $view = 'livewire.pages.audit';

    /** Server-wide settings: administrators only. */
    protected static ?string $permission = 'admin';

    protected ?string $title = 'Audit agenta';

    protected static ?string $slug = 'audit';

    protected static ?string $navigationLabel = 'Audit';

    protected static ?string $navigationIcon = 'outline:clipboard-document-list';

    protected static ?string $navigationGroup = 'server';

    protected static int $navigationSort = 40;
}
