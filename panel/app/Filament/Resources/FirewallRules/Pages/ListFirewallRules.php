<?php

namespace App\Filament\Resources\FirewallRules\Pages;

use App\Actions\Firewall\ManageFirewall;
use App\Actions\Firewall\SyncFirewall;
use App\Filament\Resources\FirewallRules\FirewallRuleResource;
use App\Services\Agent\AgentException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

class ListFirewallRules extends ListRecords
{
    protected static string $resource = FirewallRuleResource::class;

    public function getSubheading(): ?string
    {
        $status = Cache::get('firewall.status');
        if ($status === null) {
            return 'Stav firewallu zatím nebyl ověřen.';
        }

        return ($status['active'] ? 'UFW je aktivní.' : 'UFW je VYPNUTÝ — pravidla se neuplatňují.')
            .' Porty '.implode(', ', $status['protected_ports']).' jsou chráněné proti zablokování.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label('Přidat pravidlo')
                ->icon(Heroicon::OutlinedPlus)
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('action')
                            ->label('Akce')
                            ->options(['allow' => 'Povolit', 'deny' => 'Zakázat'])
                            ->default('allow')
                            ->required(),
                        TextInput::make('port')
                            ->label('Port')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->required(),
                        Select::make('proto')
                            ->label('Protokol')
                            ->options(['tcp' => 'TCP', 'udp' => 'UDP'])
                            ->default('tcp')
                            ->required(),
                    ]),
                    TextInput::make('from')
                        ->label('Odkud (IP nebo síť)')
                        ->placeholder('odkudkoli')
                        ->helperText('Např. 203.0.113.10 nebo 10.0.0.0/24. Prázdné = odkudkoli.')
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                            if (filled($value) && ! self::isIpOrCidr(trim($value))) {
                                $fail('Zadejte IP adresu nebo síť v CIDR.');
                            }
                        }),
                    TextInput::make('description')->label('Popis')->maxLength(100),
                ])
                ->action(function (array $data) {
                    try {
                        app(ManageFirewall::class)->add($data);
                        Notification::make()->title('Pravidlo přidáno')->success()->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Pravidlo se nepodařilo přidat')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('sync')
                ->label('Ověřit stav')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function () {
                    try {
                        $r = app(SyncFirewall::class)->handle();
                        Notification::make()->title("Firewall ověřen ({$r['rules']} pravidel)")->success()->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Agent není dostupný')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

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
}
