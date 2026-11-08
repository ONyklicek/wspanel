<?php

namespace App\Livewire\Sites;

use App\Actions\Mail\ManageMailHosting;
use App\Livewire\Sites\Concerns\IsSiteTab;
use App\Models\MailDomain;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Resources\MailDomainResource;
use App\Wire\Resources\SiteResource;
use Illuminate\Contracts\View\View;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** The mailboxes and aliases of the site's domain (a tab of the site). */
class SiteMail extends ViewPage
{
    protected static ?string $resource = SiteResource::class;

    use IsSiteTab;

    protected function tabLabel(): string
    {
        return __('Pošta');
    }

    public function infolist(): Infolist
    {
        return Infolist::make()->schema([]);
    }

    /** The domain the site's mail is under: its own, or the one without www. */
    public static function mailDomainName(Site $site): string
    {
        return (string) preg_replace('/^www\./', '', $site->domain);
    }

    public function mailDomain(): ?MailDomain
    {
        /** @var Site $site */
        $site = $this->nativeRecord();

        return MailDomain::query()->whereIn('domain', array_unique([$site->domain, self::mailDomainName($site)]))->orderByRaw('domain = ? desc', [$site->domain])->first();
    }

    public function setUp(): void
    {
        /** @var Site $site */
        $site = $this->nativeRecord();
        try {
            app(ManageMailHosting::class)->addDomain(self::mailDomainName($site));
            Toast::success(__('Pošta pro :domain zřízena', ['domain' => self::mailDomainName($site)]), __('Založte schránky. MX záznam: ').ManageMailHosting::mailHost());
        } catch (AgentException $e) {
            Toast::error(__('Poštu se nepodařilo zřídit'), $e->getMessage());
        }
    }

    protected function headerActions(): array
    {
        return [
            Action::make('mailDomain')
                ->label(__('Nastavení domény'))
                ->icon('outline:cog-6-tooth')
                ->color('gray')
                ->visible(fn () => $this->mailDomain() !== null)
                ->url(fn () => MailDomainResource::url('view', $this->mailDomain())),
        ];
    }

    public function render(): View
    {
        /** @var Site $site */
        $site = $this->nativeRecord();
        $domain = $this->mailDomain();
        $hosting = app(ManageMailHosting::class);
        if ($domain) {
            $hosting->refreshUsage($domain);
        }

        return view('livewire.sites.mail', [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => $this->subNavigation($site),
            'site' => $site,
            'domain' => $domain,
            'installed' => $domain !== null || $hosting->installed(),
            'name' => self::mailDomainName($site),
            'settings' => $domain ? ManageMailHosting::clientSettings($domain) : [],
        ]);
    }
}
