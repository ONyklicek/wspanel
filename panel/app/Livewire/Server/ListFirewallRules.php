<?php

namespace App\Livewire\Server;

use App\Actions\Firewall\ManageFirewall;
use App\Actions\Firewall\SyncFirewall;
use App\Actions\ManagePanelAccess;
use App\Livewire\Concerns\TranslatesPageText;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Forms\Options;
use App\Wire\Resources\FirewallRuleResource;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Foundation\Schema\Grid;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\Textarea;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListFirewallRules extends ListPage
{
    use TranslatesPageText;

    protected static ?string $resource = FirewallRuleResource::class;

    protected ?string $title = 'Firewall';

    public static function isIpOrCidr(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (! str_contains($value, '/')) {
            return false;
        }
        [$ip, $bits] = explode('/', $value, 2);
        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : -1);

        return $max > 0 && ctype_digit($bits) && (int) $bits <= $max;
    }

    public function getSubheading(): ?string
    {
        $status = Cache::get('firewall.status');

        return $status === null ? __('Stav firewallu zatím nebyl ověřen.')
            : ($status['active'] ? __('UFW je aktivní.') : __('UFW je VYPNUTÝ: pravidla se neuplatňují.')).' Porty '.implode(', ', $status['protected_ports']).__(' jsou chráněné proti zablokování.');
    }

    protected function headerActions(): array
    {
        $access = app(ManagePanelAccess::class)->current();

        return [
            Action::make('add')
                ->label(__('Přidat pravidlo'))
                ->icon('outline:plus')
                ->form([
                    Grid::make()->columns(3)->schema([
                        Select::make('action')->rules([Options::rule()])->label(__('Akce'))->options(['allow' => __('Povolit'), 'deny' => __('Zakázat')])->default('allow')->required(),
                        TextInput::make('port')->label(__('Port'))->integer()->minValue(1)->maxValue(65535)->rules(['integer', 'between:1,65535'])->required(),
                        Select::make('proto')->rules([Options::rule()])->label(__('Protokol'))->options(['tcp' => 'TCP', 'udp' => 'UDP'])->default('tcp')->required(),
                    ]),
                    TextInput::make('from')->label(__('Odkud (IP nebo síť)'))->placeholder('odkudkoli')
                        ->helperText(__('Např. 203.0.113.10 nebo 10.0.0.0/24. Prázdné = odkudkoli.'))
                        ->rules([function (string $attribute, mixed $value, \Closure $fail) {
                            if (filled($value) && ! self::isIpOrCidr(trim((string) $value))) {
                                $fail(__('Zadejte IP adresu nebo síť v CIDR.'));
                            }
                        }]),
                    TextInput::make('description')->label(__('Popis'))->maxLength(100)->rules(['max:100']),
                ])
                ->action(function (array $data) {
                    try {
                        app(ManageFirewall::class)->add($data);
                        Toast::success(__('Pravidlo přidáno'));
                    } catch (AgentException $e) {
                        Toast::error(__('Pravidlo se nepodařilo přidat'), $e->getMessage());
                    }
                }),
            Action::make('panelAccess')
                ->label(__('Přístup k panelu'))
                ->icon('outline:lock-closed')
                ->color($access['allow'] === [] ? 'gray' : 'success')
                ->modalHeading(__('Kdo smí otevřít panel'))
                ->modalWidth('2xl')
                ->modalDescription(__('Omezí panel (i phpMyAdmin, Adminer a Mailpit na jeho doméně) na vybrané adresy, např. VPN nebo kancelář. Weby to neovlivní. Ze serveru samotného (SSH tunel) panel funguje vždy: ssh -L 8443:127.0.0.1:443 root@server.'))
                ->fillFormUsing(fn () => ['allow' => implode("\n", $access['allow']), 'public_hooks' => $access['public_hooks']])
                ->form([
                    Textarea::make('allow')->label(__('Povolené adresy a sítě'))->rows(4)->placeholder("10.8.0.0/24\n203.0.113.7")
                        ->helperText(__('Jedna na řádek. Prázdné = panel je veřejný. Vaše současná adresa: ').request()->ip()),
                    Toggle::make('public_hooks')->label(__('Webhooky a CI API dostupné odkudkoli (deploy z GitHubu funguje dál)'))->default(true),
                ])
                ->action(function (array $data) {
                    $list = collect(preg_split('/\R/', (string) ($data['allow'] ?? '')) ?: [])->map(fn ($l) => trim($l))->filter()->values()->all();
                    foreach ($list as $entry) {
                        if (! self::isIpOrCidr($entry)) {
                            Toast::error(__('Neplatná adresa'), $entry);

                            return;
                        }
                    }
                    try {
                        app(ManagePanelAccess::class)->set($list, (bool) ($data['public_hooks'] ?? true), request()->ip());
                        Toast::success($list === [] ? __('Panel je veřejný') : __('Přístup k panelu omezen'), $list === [] ? null : implode(', ', $list));
                    } catch (InvalidArgumentException|AgentException $e) {
                        Toast::error(__('Neuloženo'), $e->getMessage());
                    }
                }),
            Action::make('sync')->label(__('Ověřit stav'))->icon('outline:arrow-path')->color('gray')
                ->action(function () {
                    try {
                        $r = app(SyncFirewall::class)->handle();
                        Toast::success(__('Firewall ověřen (:rules pravidel)', ['rules' => $r['rules']]));
                    } catch (AgentException $e) {
                        Toast::error(__('Agent není dostupný'), $e->getMessage());
                    }
                }),
        ];
    }
}
