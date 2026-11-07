<?php

namespace App\Filament\Resources\CronJobs;

use App\Filament\Resources\CronJobs\Pages\ListCronJobs;
use App\Filament\Resources\CronJobs\Tables\CronJobsTable;
use App\Models\CronJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CronJobResource extends Resource
{
    protected static ?string $model = CronJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Naplánované úlohy';

    protected static ?string $modelLabel = 'úloha';

    protected static ?string $pluralModelLabel = 'naplánované úlohy';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false; // created through the agent (header action)
    }

    public static function table(Table $table): Table
    {
        return CronJobsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListCronJobs::route('/')];
    }
}
