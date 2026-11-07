<?php

namespace App\Filament\Resources\FirewallRules;

use App\Filament\Resources\FirewallRules\Pages\ListFirewallRules;
use App\Filament\Resources\FirewallRules\Tables\FirewallRulesTable;
use App\Models\FirewallRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FirewallRuleResource extends Resource
{
    protected static ?string $model = FirewallRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Firewall';

    protected static ?string $modelLabel = 'pravidlo';

    protected static ?string $pluralModelLabel = 'pravidla firewallu';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false; // rules are added through the agent (header action)
    }

    public static function table(Table $table): Table
    {
        return FirewallRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListFirewallRules::route('/')];
    }
}
