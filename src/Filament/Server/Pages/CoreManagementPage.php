<?php

namespace Boy132\MinecraftModrinth\Filament\Server\Pages;

use Filament\Pages\Page;
use App\Models\Server;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class CoreManagementPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-cpu';

    protected static ?string $slug = 'core-management';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Core Management';

    public function getTitle(): string
    {
        return 'Core Management';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Server::query()->whereNull('id')) // Empty query for now
            ->columns([]); // Empty for now
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }
}
