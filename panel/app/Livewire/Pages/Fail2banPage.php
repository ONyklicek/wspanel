<?php

namespace App\Livewire\Pages;

use App\Actions\Security\ManageFail2ban;
use App\Livewire\Concerns\TranslatesPageText;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use NyonCode\WirePanels\Pages\Page;

/** Addresses fail2ban has banned after repeated failed sign-ins. */
class Fail2banPage extends Page
{
    use TranslatesPageText;

    protected static string $view = 'livewire.pages.fail2ban';

    protected static ?string $permission = 'admin';

    protected ?string $title = 'Zablokované adresy (fail2ban)';

    protected static ?string $slug = 'fail2ban';

    protected static ?string $navigationLabel = 'Fail2ban';

    protected static ?string $navigationIcon = 'outline:no-symbol';

    protected static ?string $navigationGroup = 'security';

    protected static int $navigationSort = 20;

    /** @var array<string, list<string>> */
    public array $bans = [];

    public ?string $error = null;

    public function mount(): void
    {
        $this->load();
    }

    public function load(): void
    {
        try {
            $this->bans = app(ManageFail2ban::class)->bans();
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function unban(string $ip): void
    {
        try {
            app(ManageFail2ban::class)->unban($ip);
            Toast::success(__(':ip odblokována', ['ip' => $ip]));
        } catch (AgentException $e) {
            Toast::error(__('Odblokování selhalo'), $e->getMessage());
        }
        $this->load();
    }
}
