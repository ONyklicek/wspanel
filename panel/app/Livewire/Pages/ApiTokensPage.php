<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\TranslatesPageText;
use NyonCode\WirePanels\Pages\Page;

/** The signed-in user's API tokens (REST API, the wspanel CLI). */
class ApiTokensPage extends Page
{
    use TranslatesPageText;

    protected static string $view = 'livewire.pages.api-tokens';

    protected ?string $title = 'API tokeny';

    protected static ?string $slug = 'api-tokens';

    protected static ?string $navigationLabel = 'API a CLI';

    protected static ?string $navigationIcon = 'outline:command-line';

    protected static ?string $navigationGroup = 'account';

    protected static int $navigationSort = 20;
}
