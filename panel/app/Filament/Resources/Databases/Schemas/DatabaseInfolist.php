<?php

namespace App\Filament\Resources\Databases\Schemas;

use App\Models\Database;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class DatabaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(4)
                ->schema([
                    TextEntry::make('engine')->label('Engine')->badge(),
                    TextEntry::make('site.domain')->label('Web')->placeholder('nepřipojeno'),
                    TextEntry::make('size')->label('Velikost')->formatStateUsing(fn (?int $state) => $state === null ? '—' : Number::fileSize($state, 1)),
                    TextEntry::make('backup_frequency')
                        ->label('Automatické zálohy')
                        ->formatStateUsing(fn (string $state, Database $record) => match ($state) {
                            'daily' => "denně, drží {$record->backup_keep}",
                            'weekly' => "týdně, drží {$record->backup_keep}",
                            default => 'vypnuto',
                        }),
                    TextEntry::make('connection')
                        ->label('Připojení')
                        ->state(fn (Database $record) => '127.0.0.1:'.($record->engine->value === 'mariadb' ? 3306 : 5432).' · uživatel '.$record->name)
                        ->columnSpan(2),
                    TextEntry::make('env')
                        ->label('Údaje')
                        ->state(fn (Database $record) => $record->site
                            ? 'zapsané v .env webu '.$record->site->domain
                            : 'heslo panel nedrží; při potřebě ho vygenerujte znovu')
                        ->columnSpan(2),
                ]),
        ]);
    }
}
