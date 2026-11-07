<?php

namespace App\Filament\Resources\Databases;

use App\Filament\Resources\Databases\Pages\ListDatabases;
use App\Filament\Resources\Databases\Pages\ViewDatabase;
use App\Filament\Resources\Databases\RelationManagers\BackupsRelationManager;
use App\Filament\Resources\Databases\Schemas\DatabaseInfolist;
use App\Filament\Resources\Databases\Tables\DatabasesTable;
use App\Models\Database;
use App\Services\Agent\SystemInfo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DatabaseResource extends Resource
{
    protected static ?string $model = Database::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $modelLabel = 'databáze';

    protected static ?string $pluralModelLabel = 'databáze';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    /** Hidden when the server has no database engine installed. */
    public static function shouldRegisterNavigation(): bool
    {
        return app(SystemInfo::class)->dbEngines() !== [];
    }

    public static function canCreate(): bool
    {
        return false; // created through the agent (header action on the list)
    }

    public static function infolist(Schema $schema): Schema
    {
        return DatabaseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DatabasesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [BackupsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDatabases::route('/'),
            'view' => ViewDatabase::route('/{record}'),
        ];
    }
}
